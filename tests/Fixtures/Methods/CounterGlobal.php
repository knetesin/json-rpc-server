<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

/**
 * Side-effecting counter that proves a cache hit short-circuits the
 * handler — subsequent calls return the original value, not an increment.
 */
#[Rpc\Method('cache.counter_global')]
#[Rpc\Cache(ttl: 60)]
final class CounterGlobal
{
    /** Static so the value survives re-instantiation between requests. */
    private static int $n = 0;

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['n' => ++self::$n];
    }
}
