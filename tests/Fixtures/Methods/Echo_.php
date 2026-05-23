<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Context\Context;

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
