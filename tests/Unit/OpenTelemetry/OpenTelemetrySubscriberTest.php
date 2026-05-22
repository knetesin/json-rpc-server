<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\OpenTelemetry;

use JsonRpcServer\Event\MethodInvocationCompletedEvent;
use JsonRpcServer\Event\MethodInvocationFailedEvent;
use JsonRpcServer\Event\MethodInvocationStartedEvent;
use JsonRpcServer\Event\StreamIterationCompletedEvent;
use JsonRpcServer\Event\StreamRowEmittedEvent;
use JsonRpcServer\Exception\InvalidParamsException;
use JsonRpcServer\OpenTelemetry\OpenTelemetrySubscriber;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcParams;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class OpenTelemetrySubscriberTest extends TestCase
{
    private InMemoryExporter $spanExporter;
    private MetricInMemoryExporter $metricExporter;
    private TracerProvider $tracerProvider;
    private MeterProviderInterface $meterProvider;

    protected function setUp(): void
    {
        $this->spanExporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider([new SimpleSpanProcessor($this->spanExporter)]);

        $this->metricExporter = new MetricInMemoryExporter();
        $reader = new ExportingReader($this->metricExporter);
        $this->meterProvider = MeterProvider::builder()
            ->setResource(ResourceInfoFactory::emptyResource())
            ->addReader($reader)
            ->build();
    }

    public function testCompletedCallEmitsServerSpanAndMetrics(): void
    {
        $sub = $this->subscriber();
        $meta = $this->meta('user.update');

        $sub->onStarted(new MethodInvocationStartedEvent($meta, new RpcParams(['id' => 7])));
        $sub->onCompleted(new MethodInvocationCompletedEvent($meta, new RpcParams(['id' => 7]), ['ok' => true], 0.042));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('user.update', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_SERVER, $spans[0]->getKind());
        $this->assertSame('jsonrpc', $spans[0]->getAttributes()->get('rpc.system'));
        $this->assertSame('user.update', $spans[0]->getAttributes()->get('rpc.method'));
        $this->assertSame(StatusCode::STATUS_OK, $spans[0]->getStatus()->getCode());

        $metrics = $this->collectMetrics();
        $this->assertArrayHasKey('rpc.server.duration', $metrics);
        $this->assertArrayHasKey('rpc.server.requests', $metrics);
    }

    public function testIgnoredExceptionDoesNotMarkSpanAsError(): void
    {
        $sub = $this->subscriber(ignoreExceptions: [InvalidParamsException::class]);
        $meta = $this->meta('user.create');

        $sub->onStarted(new MethodInvocationStartedEvent($meta, new RpcParams(null)));
        $sub->onFailed(new MethodInvocationFailedEvent(
            $meta,
            new RpcParams(null),
            new InvalidParamsException('Invalid params'),
            0.01,
        ));

        $spans = $this->spanExporter->getSpans();
        $this->assertCount(1, $spans);
        // Status stays UNSET (default) — ignored exceptions do not flip the SLO bit.
        $this->assertNotSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        $this->assertSame(-32602, $spans[0]->getAttributes()->get('rpc.jsonrpc.error_code'));
    }

    public function testUnknownExceptionMarksSpanAsError(): void
    {
        $sub = $this->subscriber();
        $meta = $this->meta('user.update');

        $sub->onStarted(new MethodInvocationStartedEvent($meta, new RpcParams(null)));
        $sub->onFailed(new MethodInvocationFailedEvent(
            $meta,
            new RpcParams(null),
            new \RuntimeException('boom'),
            0.05,
        ));

        $spans = $this->spanExporter->getSpans();
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testStreamSpanWaitsForIterationCompleted(): void
    {
        $sub = $this->subscriber();
        $meta = $this->meta('auto.export', streaming: true);

        $sub->onStarted(new MethodInvocationStartedEvent($meta, new RpcParams(null)));
        // Dispatcher fires Completed when iterator is returned — span must stay open.
        $sub->onCompleted(new MethodInvocationCompletedEvent($meta, new RpcParams(null), null, 0.001));
        $this->assertCount(0, $this->spanExporter->getSpans(), 'stream span must still be open');

        $sub->onStreamRow(new StreamRowEmittedEvent($meta, ['a' => 1], 0));
        $sub->onStreamRow(new StreamRowEmittedEvent($meta, ['a' => 2], 1));
        $sub->onStreamCompleted(new StreamIterationCompletedEvent($meta, rowCount: 2, durationSec: 0.5));

        $spans = $this->spanExporter->getSpans();
        // 1 stream span; no per-row spans because span_per_row is off.
        $this->assertCount(1, $spans);
        $this->assertSame('auto.export', $spans[0]->getName());
        $this->assertSame(2, $spans[0]->getAttributes()->get('rpc.stream.row_count'));
    }

    public function testSpanPerRowFlagOpensExtraSpans(): void
    {
        $sub = $this->subscriber(streamSpanPerRow: true);
        $meta = $this->meta('auto.export', streaming: true);

        $sub->onStarted(new MethodInvocationStartedEvent($meta, new RpcParams(null)));
        $sub->onCompleted(new MethodInvocationCompletedEvent($meta, new RpcParams(null), null, 0.001));
        $sub->onStreamRow(new StreamRowEmittedEvent($meta, ['a' => 1], 0));
        $sub->onStreamRow(new StreamRowEmittedEvent($meta, ['a' => 2], 1));
        $sub->onStreamCompleted(new StreamIterationCompletedEvent($meta, 2, 0.5));

        $spans = $this->spanExporter->getSpans();
        // 2 row spans + 1 stream span. Row spans finish first (SimpleSpanProcessor flushes on end()).
        $this->assertCount(3, $spans);
        $rowSpans = array_filter($spans, static fn ($s) => 'row' === $s->getName());
        $this->assertCount(2, $rowSpans);
    }

    /**
     * @param list<class-string<\Throwable>> $ignoreExceptions
     */
    private function subscriber(array $ignoreExceptions = [], bool $streamSpanPerRow = false): OpenTelemetrySubscriber
    {
        $tracer = $this->tracerProvider->getTracer('json-rpc-test');
        $meter = $this->meterProvider->getMeter('json-rpc-test');

        return new OpenTelemetrySubscriber(
            tracer: $tracer,
            durationHistogram: $meter->createHistogram('rpc.server.duration', 'ms'),
            requestCounter: $meter->createCounter('rpc.server.requests'),
            requestStack: null,
            traces: true,
            metrics: true,
            propagate: false,           // skip propagation tests — covered by SDK itself
            streamRecordRowCount: true,
            streamSpanPerRow: $streamSpanPerRow,
            ignoreExceptions: $ignoreExceptions,
        );
    }

    private function meta(string $name, bool $streaming = false): MethodMetadata
    {
        return new MethodMetadata(
            name: $name,
            serviceClass: 'App\\Stub',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: $streaming,
            streamFormat: null,
        );
    }

    /**
     * @return array<string, true>
     */
    private function collectMetrics(): array
    {
        $this->meterProvider->forceFlush();
        $names = [];
        foreach ($this->metricExporter->collect() as $metric) {
            $names[$metric->name] = true;
        }

        return $names;
    }
}
