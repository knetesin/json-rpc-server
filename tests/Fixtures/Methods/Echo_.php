<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Context\Context;

#[Rpc\Method('test.echo')]
#[Rpc\Mcp(description: 'Echoes the message back.')]
final class Echo_
{
    /** @return array<string, mixed> */
    public function __invoke(EchoRequest $req, Context $ctx): array
    {
        return [
            'pong' => $req->message,
            'method' => $ctx->methodName,
        ];
    }
}
