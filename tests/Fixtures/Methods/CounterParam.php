<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;

#[Rpc\Method('cache.counter_param')]
#[Rpc\Cache(ttl: 60)]
final class CounterParam
{
    /** @var array<string, int> */
    private static array $calls = [];

    /** @return array<string, mixed> */
    public function __invoke(RpcRequest $req): array
    {
        $key = $req->params->getString('k');
        self::$calls[$key] = (self::$calls[$key] ?? 0) + 1;

        return ['k' => $key, 'n' => self::$calls[$key]];
    }
}
