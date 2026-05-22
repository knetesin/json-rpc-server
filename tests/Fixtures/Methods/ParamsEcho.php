<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Request\RpcRequest;

#[Rpc\Method('test.params_echo')]
final class ParamsEcho
{
    /** @return array<string, mixed> */
    public function __invoke(RpcRequest $req): array
    {
        return [
            'all' => $req->params->all(),
            'count' => $req->params->count(),
            'isList' => $req->params->isList(),
            'isEmpty' => $req->params->isEmpty(),
        ];
    }
}
