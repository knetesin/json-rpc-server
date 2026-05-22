<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Request\RpcRequest;

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
