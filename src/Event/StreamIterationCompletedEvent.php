<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Event;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;

/**
 * Dispatched by StreamController after the iterator is fully consumed
 * (without an exception). For streaming methods this is the real
 * completion marker — Dispatcher::call already fires
 * MethodInvocationCompletedEvent the moment the generator is returned,
 * BEFORE iteration starts, because for streams the "result" IS the
 * iterator.
 *
 * Subscribers interested in total wall-time and row count of a stream
 * should listen to this event, not to MethodInvocationCompletedEvent.
 */
final readonly class StreamIterationCompletedEvent
{
    public function __construct(
        public MethodMetadata $method,
        public int $rowCount,
        public float $durationSec,
    ) {
    }
}
