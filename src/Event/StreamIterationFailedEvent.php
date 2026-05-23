<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Event;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;

/**
 * Dispatched by StreamController when the iterator throws mid-stream.
 *
 * `rowCount` is the number of rows successfully emitted before the
 * failure — useful for partial-progress diagnostics in traces.
 */
final readonly class StreamIterationFailedEvent
{
    public function __construct(
        public MethodMetadata $method,
        public \Throwable $exception,
        public int $rowCount,
        public float $durationSec,
    ) {
    }
}
