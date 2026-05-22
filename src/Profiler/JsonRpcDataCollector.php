<?php

declare(strict_types=1);

namespace JsonRpcServer\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Collects JSON-RPC method invocations for the Symfony Web Profiler.
 */
final class JsonRpcDataCollector extends DataCollector
{
    /**
     * @var list<array{
     *     method: string,
     *     handler: string,
     *     params: mixed,
     *     status: string,
     *     duration_ms: float,
     *     cache_hit: bool,
     *     result: mixed,
     *     exception_class: ?string,
     *     exception_message: ?string,
     * }>
     */
    private array $calls = [];

    /**
     * @var list<array{
     *     batch_size: int,
     *     decision: string,
     *     total_duration_ms: float,
     *     subcall_durations_ms: list<float>,
     *     fanout_depth: int,
     *     inflight_at_start: int,
     * }>
     */
    private array $dispatches = [];

    /**
     * @param array<string, mixed> $params
     */
    public function startCall(string $method, string $handler, array $params): void
    {
        $this->calls[] = [
            'method' => $method,
            'handler' => $handler,
            'params' => $params,
            'status' => 'pending',
            'duration_ms' => 0.0,
            'cache_hit' => false,
            'result' => null,
            'exception_class' => null,
            'exception_message' => null,
        ];
    }

    public function completeCall(float $durationSec, mixed $result, bool $cacheHit): void
    {
        if ([] === $this->calls) {
            return;
        }
        $index = \count($this->calls) - 1;
        $this->calls[$index]['status'] = 'ok';
        $this->calls[$index]['duration_ms'] = round($durationSec * 1000, 3);
        $this->calls[$index]['cache_hit'] = $cacheHit;
        $this->calls[$index]['result'] = $result;
    }

    public function failCall(float $durationSec, \Throwable $exception): void
    {
        if ([] === $this->calls) {
            return;
        }
        $index = \count($this->calls) - 1;
        $this->calls[$index]['status'] = 'error';
        $this->calls[$index]['duration_ms'] = round($durationSec * 1000, 3);
        $this->calls[$index]['exception_class'] = $exception::class;
        $this->calls[$index]['exception_message'] = $exception->getMessage();
    }

    /**
     * @param list<float> $subcallDurationsSec
     */
    public function recordDispatch(int $batchSize, string $decision, float $totalDurationSec, array $subcallDurationsSec, int $fanoutDepth, int $inflightAtStart): void
    {
        $this->dispatches[] = [
            'batch_size' => $batchSize,
            'decision' => $decision,
            'total_duration_ms' => round($totalDurationSec * 1000, 3),
            'subcall_durations_ms' => array_map(static fn (float $s): float => round($s * 1000, 3), $subcallDurationsSec),
            'fanout_depth' => $fanoutDepth,
            'inflight_at_start' => $inflightAtStart,
        ];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $totalMs = 0.0;
        foreach ($this->calls as $call) {
            $totalMs += $call['duration_ms'];
        }

        $this->data = [
            'calls' => $this->cloneVar($this->calls),
            'call_count' => \count($this->calls),
            'total_duration_ms' => round($totalMs, 3),
            'dispatches' => $this->cloneVar($this->dispatches),
            'dispatch_count' => \count($this->dispatches),
        ];
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->dispatches = [];
        $this->data = [];
    }

    public function getName(): string
    {
        return 'json_rpc';
    }

    public function getCallCount(): int
    {
        return $this->data['call_count'] ?? 0;
    }

    public function getTotalDurationMs(): float
    {
        return $this->data['total_duration_ms'] ?? 0.0;
    }

    /**
     * @return Data|list<array<string, mixed>>
     */
    public function getCalls(): Data|array
    {
        return $this->data['calls'] ?? [];
    }

    /**
     * @return Data|list<array<string, mixed>>
     */
    public function getDispatches(): Data|array
    {
        return $this->data['dispatches'] ?? [];
    }

    public function getDispatchCount(): int
    {
        return $this->data['dispatch_count'] ?? 0;
    }

    public function hasError(): bool
    {
        foreach ($this->calls as $call) {
            if ('error' === $call['status']) {
                return true;
            }
        }

        return false;
    }
}
