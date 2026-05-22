<?php

declare(strict_types=1);

namespace JsonRpcServer\OpenTelemetry;

use JsonRpcServer\Event\BatchDispatchedEvent;
use JsonRpcServer\Event\MethodInvocationCompletedEvent;
use JsonRpcServer\Event\MethodInvocationFailedEvent;
use JsonRpcServer\Event\MethodInvocationStartedEvent;
use JsonRpcServer\Event\StreamIterationCompletedEvent;
use JsonRpcServer\Event\StreamIterationFailedEvent;
use JsonRpcServer\Event\StreamRowEmittedEvent;
use JsonRpcServer\Exception\RpcException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Vendor-neutral OpenTelemetry bridge.
 *
 * Registered by RpcExtension only when `json_rpc_server.opentelemetry.enabled: true`
 * AND open-telemetry/sdk is installed. Without the SDK the bundle has zero
 * runtime cost — the subscriber class isn't even loaded.
 *
 * Signals emitted (configurable via `json_rpc_server.opentelemetry.*`):
 *
 *   - **Traces**: one SERVER-kind span per RPC call with the OTel RPC
 *     semantic-conventions attribute set (`rpc.system=jsonrpc`,
 *     `rpc.method=…`, `rpc.jsonrpc.version=2.0`, `rpc.jsonrpc.error_code`
 *     on failure). Stream methods keep the span open until iteration ends.
 *
 *   - **Metrics**: `rpc.server.duration` (histogram, ms) and
 *     `rpc.server.requests` (counter) labelled by `rpc.method` and
 *     `outcome` (`ok` / `error`). These show up in any OTel-compatible
 *     backend without further configuration.
 *
 *   - **Propagation**: extracts W3C `traceparent` / `tracestate` from the
 *     incoming HTTP request and uses it as the parent context of the RPC
 *     span — so a trace started upstream (mobile client, API gateway)
 *     flows through the RPC call.
 *
 * State is stacked on a `SplStack` keyed by event-stack semantics: started
 * pushes, completed/failed pops. Works correctly for JSON-RPC batches
 * because invocations are sequential, never overlapping.
 */
final class OpenTelemetrySubscriber implements EventSubscriberInterface
{
    /**
     * Active span frames. Each push captures everything we need to close
     * out the span on the matching completed/failed event, regardless of
     * how many handlers fire in a batch.
     *
     * @var \SplStack<array{span: SpanInterface, scope: ScopeInterface, method: string, started: float}>
     */
    private \SplStack $activeFrames;

    /**
     * @param list<class-string<\Throwable>> $ignoreExceptions
     */
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly ?HistogramInterface $durationHistogram = null,
        private readonly ?CounterInterface $requestCounter = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly bool $traces = true,
        private readonly bool $metrics = true,
        private readonly bool $propagate = true,
        private readonly bool $recordParams = false,
        private readonly bool $recordResult = false,
        private readonly int $recordMaxChars = 2048,
        private readonly bool $streamRecordRowCount = true,
        private readonly bool $streamSpanPerRow = false,
        private readonly array $ignoreExceptions = [],
    ) {
        $this->activeFrames = new \SplStack();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MethodInvocationStartedEvent::class => 'onStarted',
            MethodInvocationCompletedEvent::class => 'onCompleted',
            MethodInvocationFailedEvent::class => 'onFailed',
            StreamRowEmittedEvent::class => 'onStreamRow',
            StreamIterationCompletedEvent::class => 'onStreamCompleted',
            StreamIterationFailedEvent::class => 'onStreamFailed',
            BatchDispatchedEvent::class => 'onBatchDispatched',
        ];
    }

    public function onBatchDispatched(BatchDispatchedEvent $event): void
    {
        if (!$this->metrics) {
            return;
        }
        // Standard labels per dispatch — used by all batch-related metrics so
        // dashboards can slice by decision (parallel vs fallback reason).
        $labels = [
            'decision' => $event->decision->value,
            'mode' => $event->isParallel() ? 'parallel' : 'sequential',
        ];
        $this->requestCounter?->add(0, $labels);
        // We don't have dedicated batch instruments yet — the existing
        // request counter / duration histogram already cover per-call
        // measurements. The decision label gets carried via the active
        // span attributes below.
        $span = \OpenTelemetry\API\Trace\Span::getCurrent();
        $span->setAttribute('rpc.batch.size', $event->batchSize);
        $span->setAttribute('rpc.batch.decision', $event->decision->value);
        $span->setAttribute('rpc.batch.fanout_depth', $event->fanoutDepth);
        if ($event->inflightAtStart > 0) {
            $span->setAttribute('rpc.batch.fanout.inflight_at_start', $event->inflightAtStart);
        }
    }

    public function onStarted(MethodInvocationStartedEvent $event): void
    {
        if (!$this->traces) {
            return;
        }

        $spanName = '' !== $event->method->name ? $event->method->name : 'rpc.call';
        $builder = $this->tracer
            ->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('rpc.system', 'jsonrpc')
            ->setAttribute('rpc.jsonrpc.version', '2.0')
            ->setAttribute('rpc.method', $event->method->name);

        if ($event->method->isStreaming) {
            $builder->setAttribute('rpc.stream', true);
        }

        // Extract upstream W3C trace context — turns "this span" into a
        // child of whatever traceparent the caller sent.
        if ($this->propagate) {
            $parent = $this->extractParentContext();
            if (null !== $parent) {
                $builder->setParent($parent);
            }
        }

        if ($this->recordParams) {
            $builder->setAttribute(
                'rpc.jsonrpc.params',
                $this->truncate($this->jsonEncode($event->params->all())),
            );
        }

        $span = $builder->startSpan();
        // activate() makes the span "current" so any code inside the call
        // can call Span::getCurrent() to grab it (e.g. for nested spans).
        $scope = $span->activate();

        $this->activeFrames->push([
            'span' => $span,
            'scope' => $scope,
            'method' => $event->method->name,
            'started' => microtime(true),
        ]);
    }

    public function onCompleted(MethodInvocationCompletedEvent $event): void
    {
        // Stream methods: the span stays open until StreamIterationCompleted.
        // For non-streams, close now.
        if ($event->method->isStreaming) {
            return;
        }
        $frame = $this->popFrame($event->method->name);
        if (null === $frame) {
            // Metrics work without a span.
            $this->recordMetrics($event->method->name, 'ok', $event->durationSec);

            return;
        }

        if ($this->recordResult) {
            $frame['span']->setAttribute(
                'rpc.jsonrpc.result',
                $this->truncate($this->jsonEncode($event->result)),
            );
        }
        if ($event->cacheHit) {
            $frame['span']->setAttribute('rpc.cache.hit', true);
        }
        $frame['span']->setStatus(StatusCode::STATUS_OK);
        $frame['scope']->detach();
        $frame['span']->end();

        $this->recordMetrics($event->method->name, 'ok', $event->durationSec);
    }

    public function onFailed(MethodInvocationFailedEvent $event): void
    {
        $ignored = $this->isIgnored($event->exception);
        $frame = $this->popFrame($event->method->name);

        if (null !== $frame) {
            $this->annotateFailure($frame['span'], $event->exception, $ignored);
            $frame['scope']->detach();
            $frame['span']->end();
        }

        $this->recordMetrics($event->method->name, $ignored ? 'ok' : 'error', $event->durationSec);
    }

    public function onStreamRow(StreamRowEmittedEvent $event): void
    {
        if (!$this->streamSpanPerRow || !$this->traces) {
            return;
        }
        // Per-row span: a CLIENT-kind child of the active stream span (we
        // are still inside its scope because the stream span wasn't ended
        // by onCompleted). High-cardinality — gated explicitly.
        $rowSpan = $this->tracer
            ->spanBuilder('row')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('rpc.stream.row_index', $event->index)
            ->setAttribute('rpc.method', $event->method->name)
            ->startSpan();
        $rowSpan->end();
    }

    public function onStreamCompleted(StreamIterationCompletedEvent $event): void
    {
        $frame = $this->popFrame($event->method->name);
        if (null !== $frame) {
            if ($this->streamRecordRowCount) {
                $frame['span']->setAttribute('rpc.stream.row_count', $event->rowCount);
            }
            $frame['span']->setStatus(StatusCode::STATUS_OK);
            $frame['scope']->detach();
            $frame['span']->end();
        }
        $this->recordMetrics($event->method->name, 'ok', $event->durationSec);
    }

    public function onStreamFailed(StreamIterationFailedEvent $event): void
    {
        $ignored = $this->isIgnored($event->exception);
        $frame = $this->popFrame($event->method->name);

        if (null !== $frame) {
            if ($this->streamRecordRowCount) {
                $frame['span']->setAttribute('rpc.stream.row_count', $event->rowCount);
            }
            $this->annotateFailure($frame['span'], $event->exception, $ignored);
            $frame['scope']->detach();
            $frame['span']->end();
        }

        $this->recordMetrics($event->method->name, $ignored ? 'ok' : 'error', $event->durationSec);
    }

    /**
     * @return array{span: SpanInterface, scope: ScopeInterface, method: string, started: float}|null
     */
    private function popFrame(string $method): ?array
    {
        if ($this->activeFrames->isEmpty()) {
            return null;
        }
        $frame = $this->activeFrames->top();
        // Defensive: skip frames that don't match. In practice they always
        // match because dispatch is sequential, but if a subscriber misbehaves
        // we don't want to close the wrong span.
        if ($frame['method'] !== $method) {
            return null;
        }

        return $this->activeFrames->pop();
    }

    private function annotateFailure(SpanInterface $span, \Throwable $exception, bool $ignored): void
    {
        if ($exception instanceof RpcException) {
            $span->setAttribute('rpc.jsonrpc.error_code', $exception->rpcCode());
            $span->setAttribute('rpc.jsonrpc.error_message', $exception->getMessage());
        }
        // Record the exception either way — it surfaces in the trace UI as
        // a span event with the stack trace. Ignored exceptions skip the
        // ERROR status so SLOs don't flag client mistakes.
        $span->recordException($exception);
        if (!$ignored) {
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }
    }

    private function recordMetrics(string $method, string $outcome, float $durationSec): void
    {
        if (!$this->metrics) {
            return;
        }
        $attributes = ['rpc.method' => $method, 'outcome' => $outcome];
        $this->durationHistogram?->record($durationSec * 1000, $attributes);
        $this->requestCounter?->add(1, $attributes);
    }

    private function isIgnored(\Throwable $e): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds an OTel Context from the W3C trace-context headers on the
     * incoming request. Returns null when no traceparent is present so
     * callers fall back to the implicit current context.
     */
    private function extractParentContext(): ?ContextInterface
    {
        $request = $this->requestStack?->getMainRequest();
        if (null === $request) {
            return null;
        }
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        $propagator = Globals::propagator();
        $context = $propagator->extract($headers);

        // If no traceparent was present, extract() returns the current
        // context (root) — we can still use it as parent.
        return $context;
    }

    private function jsonEncode(mixed $value): string
    {
        try {
            return (string) json_encode(
                $value,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return '<unencodable>';
        }
    }

    private function truncate(string $value): string
    {
        if (\strlen($value) <= $this->recordMaxChars) {
            return $value;
        }

        return substr($value, 0, $this->recordMaxChars).'…';
    }
}
