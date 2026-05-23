<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Cache\Scope\IpScope;

#[Rpc\Method('cache.counter_ip')]
#[Rpc\Cache(ttl: 60, scope: IpScope::class)]
final class CounterIp
{
    private static int $n = 0;

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['n' => ++self::$n];
    }
}
