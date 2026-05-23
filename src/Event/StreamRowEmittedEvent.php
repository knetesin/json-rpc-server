<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Event;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;

/**
 * Dispatched by StreamController for every row a streaming method yields,
 * AFTER normalization and BEFORE the row is written to the wire.
 *
 * Subscribers should keep listeners cheap — this event fires in the hot
 * iteration loop of the stream endpoint. The OpenTelemetry bridge uses it
 * to optionally open a per-row child span (gated by config, off by default).
 */
final readonly class StreamRowEmittedEvent
{
    public function __construct(
        public MethodMetadata $method,
        public mixed $row,
        public int $index,
    ) {
    }
}
