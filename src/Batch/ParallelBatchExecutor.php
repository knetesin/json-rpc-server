<?php

declare(strict_types=1);

namespace JsonRpcServer\Batch;

use JsonRpcServer\Exception\InternalErrorException;
use JsonRpcServer\Exception\RpcErrorEnvelope;
use JsonRpcServer\Request\RpcRequest;
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
 * Failure semantics: a sub-call that times out or errors at the transport
 * level becomes a per-item error envelope in the result list. Other items
 * are unaffected — the batch never blows up because one row was slow.
 *
 * This class is the **fan-out implementation only**. The decision of
 * whether to fan out or not is made one layer up in
 * {@see \JsonRpcServer\Controller\RpcController}; this class is invoked
 * only when that decision is "Parallel".
 */
final class ParallelBatchExecutor
{
    /** HTTP header that carries the current fan-out depth for the recursion guard. */
    public const string DEPTH_HEADER = 'X-Rpc-Fanout-Depth';

    private readonly LoggerInterface $logger;

    private readonly float $connectTimeoutSec;

    /**
     * @param positive-int $maxConcurrency
     * @param list<string> $forwardHeaders incoming-request headers to copy to each sub-call
     * @param string|null $selfUrl explicit endpoint URL; null = derive from current request
     * @param positive-int $maxJsonDepth max nesting depth for decoded sub-call responses
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly int $maxConcurrency,
        private readonly float $timeoutSec,
        float $connectTimeoutSec,
        private readonly array $forwardHeaders,
        private readonly ?string $selfUrl,
        ?LoggerInterface $logger = null,
        private readonly int $maxJsonDepth = 32,
    ) {
        // Min 0.05 to keep us out of the zero-sleep loops some curl versions
        // hit when connect_timeout is non-positive.
        $this->connectTimeoutSec = max(0.05, $connectTimeoutSec);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param list<RpcRequest> $items
     * @param HttpRequest $original the request that brought in the batch — used to forward headers and derive self URL
     * @param int $currentDepth fan-out depth of the parent (0 for top-level)
     *
     * @return array{responses: list<array<string, mixed>>, durations: list<float>}
     */
    public function execute(array $items, HttpRequest $original, int $currentDepth): array
    {
        $url = $this->resolveSelfUrl($original);
        $headers = $this->forwardingHeaders($original, $currentDepth + 1);

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
                $body = (string) json_encode($this->itemEnvelope($req), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
                try {
                    $resp = $this->http->request('POST', $url, [
                        'body' => $body,
                        'headers' => $headers,
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
                    $responses[] = RpcErrorEnvelope::jsonRpc($req->id, new InternalErrorException(previous: $e));
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

                    if (204 === $status) {
                        // Notification: no body, no response entry. Sub-call
                        // honored the spec — we honor it back by not adding
                        // anything to the batch response.
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
                    $responses[] = RpcErrorEnvelope::jsonRpc($entry['request']->id, new InternalErrorException(previous: $e));
                }
                $durations[] = $duration;
            }
        }

        return ['responses' => $responses, 'durations' => $durations];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemEnvelope(RpcRequest $req): array
    {
        $envelope = ['jsonrpc' => '2.0', 'method' => $req->method];
        if (!$req->params->isEmpty()) {
            $envelope['params'] = $req->params->all();
        }
        if (!$req->isNotification) {
            $envelope['id'] = $req->id;
        }

        return $envelope;
    }

    private function resolveSelfUrl(HttpRequest $original): string
    {
        if (null !== $this->selfUrl && '' !== $this->selfUrl) {
            return $this->selfUrl;
        }

        // Same scheme + host as the incoming request, hitting the same path.
        // The path-from-original is intentional: lets the bundle be mounted
        // under any prefix, the fan-out still goes back to the same one.
        return $original->getSchemeAndHttpHost().$original->getPathInfo();
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
        foreach ($this->forwardHeaders as $name) {
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
