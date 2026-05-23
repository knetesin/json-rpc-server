<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Event;

use Knetesin\JsonRpcServerBundle\Batch\FanoutDecision;

/**
 * Dispatched once per batch (or single-call: batchSize=1) after the controller
 * has decided how to execute it and the dispatch finished.
 *
 * Subscribed to by:
 *   - {@see \Knetesin\JsonRpcServerBundle\Profiler\JsonRpcDataCollector} → Web Profiler panel
 *   - {@see \Knetesin\JsonRpcServerBundle\OpenTelemetry\OpenTelemetrySubscriber} → metrics
 *
 * Subscribers must keep listeners cheap — fires inside the HTTP request path.
 */
final readonly class BatchDispatchedEvent
{
    /**
     * @param list<float> $subcallDurationsSec wall-time of each sub-call when ran in parallel; empty list otherwise
     */
    public function __construct(
        public int $batchSize,
        public FanoutDecision $decision,
        public float $totalDurationSec,
        public array $subcallDurationsSec = [],
        public int $fanoutDepth = 0,
        public int $inflightAtStart = 0,
    ) {
    }

    public function isParallel(): bool
    {
        return FanoutDecision::Parallel === $this->decision;
    }
}
