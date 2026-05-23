<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Cache;

/**
 * Marker wrapper around a cache hit so callers can distinguish `null` cached
 * value from a missing entry.
 */
final readonly class CachedResult
{
    public function __construct(public mixed $value)
    {
    }
}
