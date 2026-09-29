<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Batch;

use Knetesin\JsonRpcServerBundle\Exception\InternalErrorException;
use Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException;
use Knetesin\JsonRpcServerBundle\Exception\RpcErrorEnvelope;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;
use Knetesin\JsonRpcServerBundle\Http\FanoutClientIpSigner;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Executes a batch of {@see RpcRequest} items by fan-out to the same server
 * over loopback HTTP — each item becomes its own POST, picked up by a
 * different PHP-FPM / RoadRunner / Swoole worker, so handlers run truly in
 * parallel.
 *
 * Concurrency is fenced by THREE independent layers:
 *
 *   1. Per-batch cap (`$maxConcurrency`) — at most N parallel sub-calls
 *      regardless of batch size.
 *   2. System-wide budget ({@see BudgetTrackerInterface}) — across all
 *      concurrent parents, never more than the configured ceiling.
 *   3. Recursion guard (header `X-Rpc-Fanout-Depth`) — a sub-call that
 *      tries to batch-fanout finds itself above `max_depth` and silently
 *      degrades to sequential.
 *
 * Client IP: with a {@see FanoutClientIpSigner}, every sub-call carries the
 * original client IP plus an HMAC over that IP, the depth and the body, so
 * per-IP rate limits and cache scopes in the sub-call see the real client
 * rather than the loopback connection.
 *
 * Failure semantics: a sub-call that times out or errors at the transport
 * level becomes a per-item error envelope in the result list. Other items
 * are unaffected — the batch never blows up because one row was slow.
 *
 * This class is the **fan-out implementation only**. The decision of
 * whether to fan out or not is made one layer up in
 * {@see \Knetesin\JsonRpcServerBundle\Controller\RpcController}; this class is invoked
 * only when that decision is "Parallel".
 */
final class ParallelBatchExecutor
{
    /** HTTP header that carries the current fan-out depth for the recursion guard. */
    public const string DEPTH_HEADER = 'X-Rpc-Fanout-Depth';

    private readonly LoggerInterface $logger;

    private readonly float $connectTimeoutSec;

    /** Why self_url cannot be used, or null when it can. */
    private readonly ?string $selfUrlProblem;

    private bool $selfUrlProblemLogged = false;

    /**
     * @param positive-int $maxConcurrency
     * @param list<string> $forwardHeaders incoming-request headers to copy to each sub-call
     * @param string $selfUrl absolute http(s) URL of the endpoint sub-calls are POSTed to; anything else disables fan-out, see {@see isUsable()}
     * @param positive-int $maxJsonDepth max nesting depth for decoded sub-call responses
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly int $maxConcurrency,
        private readonly float $timeoutSec,
        float $connectTimeoutSec,
        private readonly array $forwardHeaders,
        private readonly string $selfUrl,
        ?LoggerInterface $logger = null,
        private readonly int $maxJsonDepth = 32,
        private readonly ?FanoutClientIpSigner $clientIpSigner = null,
        private readonly ?ClientIpResolver $clientIps = null,
    ) {
        // Config validation cannot see env-var values, so the URL is checked
        // again here. It must not throw: RpcController receives this service on
        // every request, and a bad env value would take down single calls too.
        // Deriving the URL from the incoming Host header is not an option: that
        // would let a client redirect sub-calls, together with the forwarded
        // Authorization header, to a host of its choosing.
        $scheme = parse_url($selfUrl, \PHP_URL_SCHEME);
        $host = parse_url($selfUrl, \PHP_URL_HOST);
        $this->selfUrlProblem = \is_string($scheme) && \in_array(strtolower($scheme), ['http', 'https'], true) && \is_string($host) && '' !== $host
            ? null
            : \sprintf('json_rpc_server.parallel_batch.self_url must be an absolute http:// or https:// URL, got "%s".', $selfUrl);
        // Min 0.05 to keep us out of the zero-sleep loops some curl versions
        // hit when connect_timeout is non-positive.
        $this->connectTimeoutSec = max(0.05, $connectTimeoutSec);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * False when self_url is not an absolute http(s) URL — possible only with
     * an env-var value. Batches then run sequentially; the problem is logged
     * once per instance.
     */
    public function isUsable(): bool
    {
        if (null === $this->selfUrlProblem) {
            return true;
        }
        if (!$this->selfUrlProblemLogged) {
            $this->selfUrlProblemLogged = true;
            $this->logger->error($this->selfUrlProblem.' Batches run sequentially.');
        }

        return false;
    }

    /**
     * JSON body of the sub-call for one item. RpcController measures batch
     * items with it too, so both sides see the same size.
     *
     * @throws InvalidParamsException when the params have no JSON form, e.g. a number beyond float range that decoded to INF
     */
    public static function subcallBody(RpcRequest $req): string
    {
        $envelope = ['jsonrpc' => '2.0', 'method' => $req->method];
        if (!$req->params->isEmpty()) {
            $envelope['params'] = $req->params->all();
        }
        if (!$req->isNotification) {
            $envelope['id'] = $req->id;
        }

        try {
            return json_encode($envelope, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // The parser admits only string/int/null ids, so the culprit is in params.
            throw new InvalidParamsException('Invalid params: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param list<RpcRequest> $items
     * @param HttpRequest $original the request that brought in the batch — source of forwarded headers and the client IP
     * @param int $currentDepth fan-out depth of the parent (0 for top-level)
     *
     * @return array{responses: list<array<string, mixed>>, durations: list<float>}
     */
    public function execute(array $items, HttpRequest $original, int $currentDepth): array
    {
        if (null !== $this->selfUrlProblem) {
            throw new \LogicException($this->selfUrlProblem);
        }

        $depth = $currentDepth + 1;
        $headers = $this->forwardingHeaders($original, $depth);
        $clientIp = null === $this->clientIpSigner
            ? null
            : ($this->clientIps?->clientIpOf($original) ?? $original->getClientIp());

        /** @var list<array<string, mixed>> $responses */
        $responses = [];
        $durations = [];

        // Slice into waves of $maxConcurrency. Each wave fires off all its
        // requests "at once" (Symfony HttpClient kicks off the curl multi),
        // then we block reading the bodies. The next wave only starts after
        // the previous one drained — this keeps in-flight count bounded.
        $chunkSize = max(1, $this->maxConcurrency);
        foreach (array_chunk($items, $chunkSize) as $chunk) {
            /** @var list<array{request: RpcRequest, pending: ResponseInterface, startedAt: float}> $pending */
            $pending = [];
            foreach ($chunk as $req) {
                try {
                    $body = self::subcallBody($req);
                } catch (InvalidParamsException $e) {
                    if (!$req->isNotification) {
                        $responses[] = RpcErrorEnvelope::jsonRpc($req->id, $e);
                    }
                    $durations[] = 0.0;
                    continue;
                }
                try {
                    $resp = $this->http->request('POST', $this->selfUrl, [
                        'body' => $body,
                        'headers' => $headers + $this->clientIpHeaders($clientIp, $depth, $body),
                        'timeout' => $this->timeoutSec,
                        'max_duration' => $this->timeoutSec,
                        // Curl-specific: bail out quickly if the pool isn't
                        // accepting connections — that's our deadlock signal.
                        // Other clients (mock, native) ignore the `extra` block.
                        'extra' => [
                            'curl' => [
                                \CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeoutSec * 1000),
                            ],
                        ],
                    ]);
                } catch (HttpClientExceptionInterface $e) {
                    // Synchronous fail right at dispatch — usually means the
                    // pool is so saturated the connect failed. Treat as a
                    // per-item failure rather than blowing up the whole batch.
                    $this->logger->warning('Parallel batch sub-call could not be dispatched', [
                        'method' => $req->method,
                        'exception' => $e,
                    ]);
                    // JSON-RPC 2.0: a notification gets no response entry, not even an error.
                    if (!$req->isNotification) {
                        $responses[] = RpcErrorEnvelope::jsonRpc($req->id, new InternalErrorException(previous: $e));
                    }
                    $durations[] = 0.0;
                    continue;
                }
                $pending[] = ['request' => $req, 'pending' => $resp, 'startedAt' => microtime(true)];
            }

            foreach ($pending as $entry) {
                $duration = 0.0;
                try {
                    $body = $entry['pending']->getContent(throw: false);
                    $status = $entry['pending']->getStatusCode();
                    $duration = microtime(true) - $entry['startedAt'];

                    if (204 === $status || $entry['request']->isNotification) {
                        // JSON-RPC 2.0: a notification never gets a response
                        // entry, whatever the sub-call replied.
                        $durations[] = $duration;
                        continue;
                    }
                    $decoded = json_decode($body, true, $this->maxJsonDepth, \JSON_THROW_ON_ERROR);
                    if (!\is_array($decoded)) {
                        throw new \RuntimeException('Sub-call returned non-object response');
                    }
                    /* @var array<string, mixed> $decoded */
                    $responses[] = $decoded;
                } catch (\Throwable $e) {
                    $this->logger->warning('Parallel batch sub-call failed', [
                        'method' => $entry['request']->method,
                        'exception' => $e,
                    ]);
                    if (!$entry['request']->isNotification) {
                        $responses[] = RpcErrorEnvelope::jsonRpc($entry['request']->id, new InternalErrorException(previous: $e));
                    }
                }
                $durations[] = $duration;
            }
        }

        return ['responses' => $responses, 'durations' => $durations];
    }

    /**
     * @return array<string, string>
     */
    private function clientIpHeaders(?string $clientIp, int $depth, string $body): array
    {
        if (null === $this->clientIpSigner || null === $clientIp) {
            return [];
        }

        return [
            FanoutClientIpSigner::IP_HEADER => $clientIp,
            FanoutClientIpSigner::SIGNATURE_HEADER => $this->clientIpSigner->sign($clientIp, $depth, $body),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function forwardingHeaders(HttpRequest $original, int $depth): array
    {
        $out = [
            self::DEPTH_HEADER => (string) $depth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $reserved = array_map(strtolower(...), [self::DEPTH_HEADER, FanoutClientIpSigner::IP_HEADER, FanoutClientIpSigner::SIGNATURE_HEADER]);
        foreach ($this->forwardHeaders as $name) {
            // Internal headers are always set by the executor itself, never copied
            // from the client. HeaderBag treats "_" as "-", so compare that way too.
            if (\in_array(strtr(strtolower($name), '_', '-'), $reserved, true)) {
                continue;
            }
            $value = $original->headers->get($name);
            if (null === $value || '' === $value) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    public static function depthOf(HttpRequest $request): int
    {
        $raw = $request->headers->get(self::DEPTH_HEADER);

        return (\is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
    }
}
