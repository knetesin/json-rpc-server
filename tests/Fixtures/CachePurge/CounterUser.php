<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\CachePurge;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Cache\Scope\UserScope;

#[Rpc\Method('cache.counter_user')]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class CounterUser
{
    public static int $n = 0;

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['n' => ++self::$n];
    }
}
