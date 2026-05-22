<?php

declare(strict_types=1);

namespace JsonRpcServer\Controller;

use JsonRpcServer\Batch\BudgetTrackerInterface;
use JsonRpcServer\Batch\FanoutDecision;
use JsonRpcServer\Batch\ParallelBatchExecutor;
use JsonRpcServer\Dispatcher\Dispatcher;
use JsonRpcServer\Event\BatchDispatchedEvent;
use JsonRpcServer\Exception\InternalErrorException;
use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Exception\RateLimitExceededException;
use JsonRpcServer\Exception\RequestTooLargeException;
use JsonRpcServer\Exception\RpcErrorEnvelope;
use JsonRpcServer\Exception\RpcException;
use JsonRpcServer\Request\RpcRequest;
use JsonRpcServer\Request\RpcRequestParser;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RpcController
{
    /**
     * Default json_encode flags. `JSON_THROW_ON_ERROR` is always forced on top
     * of whatever the user configures so encoding failures surface instead of
     * silently producing `false`. Configure via `json_rpc_server.json.encode_flags`.
     */
    public const int DEFAULT_JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

    /** Default custom header carrying per-method deprecation reasons. */
    public const string DEFAULT_DEPRECATION_HEADER = 'X-Rpc-Deprecated';

    private readonly int $jsonFlags;

    public function __construct(
        private readonly RpcRequestParser $parser,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly int $defaultMaxRequestSize = 0,
        ?int $jsonEncodeFlags = null,
        /** Custom header that carries the human-readable per-method deprecation reasons. */
        private readonly string $deprecationHeader = self::DEFAULT_DEPRECATION_HEADER,
        private readonly ?ParallelBatchExecutor $parallelExecutor = null,
        private readonly ?BudgetTrackerInterface $budget = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly bool $parallelEnabled = false,
        private readonly int $parallelMinBatchSize = 2,
        private readonly int $parallelMaxDepth = 1,
    ) {
        $this->jsonFlags = ($jsonEncodeFlags ?? self::DEFAULT_JSON_FLAGS) | \JSON_THROW_ON_ERROR;
    }

    public function jsonFlags(): int
    {
        return $this->jsonFlags;
    }

    public function __invoke(Request $request): Response
    {
        $startedAt = microtime(true);
        $body = $request->getContent();

        try {
            [$isBatch, $items] = $this->parser->parse($body);
        } catch (RpcException $e) {
            // JSON-RPC 2.0 is HTTP-status-agnostic: the body's `error.code` is
            // the canonical signal. We always return 200 here — transports
            // (load balancers, retry middleware) get a uniform success status
            // and read the error from the JSON envelope.
            return $this->json(RpcErrorEnvelope::jsonRpc(null, $e));
        }

        // Per-method body-size pre-filter: items that exceed their own
        // MaxRequestSize get their own error envelope without ever reaching
        // the dispatcher. Notifications that exceed their limit drop silently
        // per JSON-RPC spec. Items that pass go on to dispatch.
        $responses = [];
        $allowed = [];
        foreach ($items as $item) {
            $tooLarge = $this->checkPerMethodLimit($item, $body);
            if (false === $tooLarge) {
                continue;
            }
            if (null !== $tooLarge) {
                $responses[] = $tooLarge;
                continue;
            }
            $allowed[] = $item;
        }

        $depth = ParallelBatchExecutor::depthOf($request);
        $inflightAtStart = $this->budget?->inflight() ?? 0;

        [$decision, $newResponses, $subcallDurations] = $this->dispatchAllowed($allowed, $request, $depth, $isBatch);
        foreach ($newResponses as $r) {
            $responses[] = $r;
        }

        $deprecations = [];
        foreach ($allowed as $item) {
            $reason = $this->deprecationReason($item);
            if (null !== $reason) {
                $deprecations[$item->method] = $reason;
            }
        }

        $this->events?->dispatch(new BatchDispatchedEvent(
            batchSize: \count($items),
            decision: $decision,
            totalDurationSec: microtime(true) - $startedAt,
            subcallDurationsSec: $subcallDurations,
            fanoutDepth: $depth,
            inflightAtStart: $inflightAtStart,
        ));

        $retryAfter = $this->maxRetryAfter($responses);

        if (!$isBatch) {
            if ([] === $responses) {
                return $this->finalize(new Response('', 204), $deprecations, $retryAfter);
            }

            return $this->finalize($this->json($responses[0]), $deprecations, $retryAfter);
        }

        if ([] === $responses) {
            return $this->finalize(new Response('', 204), $deprecations, $retryAfter);
        }

        return $this->finalize($this->json($responses), $deprecations, $retryAfter);
    }

    /**
     * Decides between parallel fan-out and in-process sequential dispatch
     * based on config + system state, runs the chosen path, and reports back
     * which path it picked (for observability).
     *
     * @param list<RpcRequest> $items
     *
     * @return array{0: FanoutDecision, 1: list<array<string, mixed>>, 2: list<float>}
     */
    private function dispatchAllowed(array $items, Request $request, int $depth, bool $isBatch): array
    {
        $size = \count($items);
        if ([] === $items) {
            return [FanoutDecision::SequentialDisabled, [], []];
        }

        // Parallel fan-out is a batch-only optimization — singletons run in-process.
        if (!$isBatch || !$this->parallelEnabled || null === $this->parallelExecutor) {
            return [FanoutDecision::SequentialDisabled, $this->dispatchSequential($items), []];
        }
        if ($size < $this->parallelMinBatchSize) {
            return [FanoutDecision::SequentialTooSmall, $this->dispatchSequential($items), []];
        }
        if ($depth >= $this->parallelMaxDepth) {
            // Recursion guard: this request is itself a sub-call of a parent
            // fan-out. Going deeper risks N-of-N-of-N explosion.
            return [FanoutDecision::SequentialDepthLimit, $this->dispatchSequential($items), []];
        }
        if (null === $this->budget) {
            return [FanoutDecision::SequentialNoBudgetStore, $this->dispatchSequential($items), []];
        }
        if (!$this->budget->reserve($size)) {
            // Worker pool already busy with other parents' fan-outs. Falling
            // back protects the pool from exhaustion / deadlock.
            return [FanoutDecision::SequentialBudgetExhausted, $this->dispatchSequential($items), []];
        }

        try {
            $result = $this->parallelExecutor->execute($items, $request, $depth);

            return [FanoutDecision::Parallel, $result['responses'], $result['durations']];
        } finally {
            $this->budget->release($size);
        }
    }

    /**
     * @param list<RpcRequest> $items
     *
     * @return list<array<string, mixed>>
     */
    private function dispatchSequential(array $items): array
    {
        $responses = [];
        foreach ($items as $item) {
            $response = $this->handleOne($item);
            if (null !== $response) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    /**
     * @param list<array<string, mixed>> $responses
     */
    private function maxRetryAfter(array $responses): ?int
    {
        $max = null;
        foreach ($responses as $r) {
            if (RateLimitExceededException::DEFAULT_CODE !== ($r['error']['code'] ?? null)) {
                continue;
            }
            $value = $r['error']['data']['retryAfter'] ?? null;
            if (!\is_int($value)) {
                continue;
            }
            $max = null === $max ? $value : max($max, $value);
        }

        return $max;
    }

    /**
     * @param array<string, string> $deprecations
     */
    private function finalize(Response $response, array $deprecations, ?int $retryAfter): Response
    {
        $this->withDeprecationHeaders($response, $deprecations);
        if (null !== $retryAfter) {
            $response->headers->set('Retry-After', (string) max(0, $retryAfter));
        }

        return $response;
    }

    private function deprecationReason(RpcRequest $req): ?string
    {
        try {
            $meta = $this->dispatcher->metadata($req->method);
        } catch (MethodNotFoundException) {
            return null;
        }

        return $meta->deprecated;
    }

    /**
     * @param array<string, string> $deprecations method => reason
     */
    private function withDeprecationHeaders(Response $response, array $deprecations): void
    {
        if ([] === $deprecations) {
            return;
        }
        // RFC 9745: Deprecation header is a structured field; we use the simple
        // form "Deprecation: true" since we don't track per-method removal dates.
        // The Sunset / reason text rides along in a custom header so clients can
        // surface it without parsing free-form fields.
        $response->headers->set('Deprecation', 'true');
        $response->headers->set(
            $this->deprecationHeader,
            implode('; ', array_map(
                static fn (string $m, string $r): string => $m.': '.$r,
                array_keys($deprecations),
                array_values($deprecations),
            )),
        );
    }

    private function json(mixed $payload, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions($this->jsonFlags);

        return $response;
    }

    /**
     * @return array<string, mixed>|false|null null = within limit (proceed),
     *                                         array = error envelope to return,
     *                                         false = notification dropped silently (204)
     */
    private function checkPerMethodLimit(RpcRequest $req, string $body): array|false|null
    {
        try {
            $meta = $this->dispatcher->metadata($req->method);
        } catch (MethodNotFoundException) {
            // Let handleOne emit the canonical -32601 in the per-item loop.
            return null;
        }

        $limit = $meta->maxRequestSize ?? $this->defaultMaxRequestSize;
        if ($limit <= 0) {
            return null;
        }

        $size = \strlen($body);
        if ($size <= $limit) {
            return null;
        }

        if ($req->isNotification) {
            // Spec: notifications never produce a response, even on errors.
            // We still must not execute the handler with an oversized body.
            return false;
        }

        return RpcErrorEnvelope::jsonRpc($req->id, new RequestTooLargeException($size, $limit));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function handleOne(RpcRequest $req): ?array
    {
        try {
            $result = $this->dispatcher->call($req);

            if ($req->isNotification) {
                return null;
            }

            return ['jsonrpc' => '2.0', 'result' => $result, 'id' => $req->id];
        } catch (RpcException $e) {
            if ($req->isNotification) {
                return null;
            }

            return RpcErrorEnvelope::jsonRpc($req->id, $e);
        } catch (\Throwable $e) {
            $this->logger->error('RPC handler failure', [
                'method' => $req->method,
                'exception' => $e,
            ]);
            if ($req->isNotification) {
                return null;
            }

            return RpcErrorEnvelope::jsonRpc($req->id, new InternalErrorException(previous: $e));
        }
    }
}
