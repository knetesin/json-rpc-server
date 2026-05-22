<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Tests\Fixtures\Cache\CountryKey;

#[Rpc\Method('cache.counter_vary')]
#[Rpc\Cache(ttl: 60, scope: CountryKey::class)]
final class CounterVary
{
    private static int $n = 0;

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['n' => ++self::$n];
    }
}
