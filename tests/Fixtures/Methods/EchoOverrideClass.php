<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Covers the class-string form of `outputSchema` — the runtime return type
 * is loose (`array`), but the published schema describes the {@see EchoReply}
 * DTO via JsonSchemaBuilder.
 */
#[Rpc\Method('test.echoOverrideClass', outputSchema: EchoReply::class)]
#[Rpc\Mcp(description: 'Echoes back; output schema points at the EchoReply DTO.')]
final class EchoOverrideClass
{
    /** @return array<string, mixed> */
    public function __invoke(EchoRequest $req): array
    {
        return ['pong' => $req->message, 'length' => \strlen($req->message)];
    }
}
