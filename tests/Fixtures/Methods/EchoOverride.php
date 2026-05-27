<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Covers the literal-array form of `outputSchema` — the response is `array`
 * (no useful auto-derived schema), so the attribute is the only thing that
 * lets MCP/OpenRPC clients see the actual shape.
 */
#[Rpc\Method('test.echoOverride', outputSchema: [
    'type' => 'object',
    'properties' => [
        'pong' => ['type' => 'string'],
        'length' => ['type' => 'integer'],
    ],
    'required' => ['pong', 'length'],
    'additionalProperties' => false,
])]
#[Rpc\Mcp(description: 'Echoes back with a hand-written output schema.')]
final class EchoOverride
{
    /** @return array<string, mixed> */
    public function __invoke(EchoRequest $req): array
    {
        return ['pong' => $req->message, 'length' => \strlen($req->message)];
    }
}
