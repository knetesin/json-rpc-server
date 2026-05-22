<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Request\RpcRequest;

#[Rpc\Method('test.params_typed')]
final class ParamsTyped
{
    /** @return array<string, mixed> */
    public function __invoke(RpcRequest $req): array
    {
        return [
            'name' => $req->params->getString('name', 'anon'),
            'age' => $req->params->getInt('age', -1),
            'active' => $req->params->getBool('active'),
            'score' => $req->params->getFloat('score', 1.5),
            'tags' => $req->params->getArray('tags'),
            'hasMissing' => $req->params->has('missing'),
        ];
    }
}
