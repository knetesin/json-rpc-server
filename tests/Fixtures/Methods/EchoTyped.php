<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('test.echoTyped')]
#[Rpc\Mcp(description: 'Echoes back with a typed DTO response.')]
final class EchoTyped
{
    public function __invoke(EchoRequest $req): EchoReply
    {
        return new EchoReply(pong: $req->message, length: \strlen($req->message));
    }
}
