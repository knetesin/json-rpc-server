<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Mcp\McpResultTransformer;

/**
 * RPC-side returns internal fields; MCP transformer strips them so the LLM
 * never sees the secret.
 */
#[Rpc\Method('test.secretBox')]
#[Rpc\Mcp(description: 'Demonstrates MCP-specific result transformation.')]
final class SecretBox implements McpResultTransformer
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'id' => 42,
            'name' => 'box',
            'secret' => 'do not show this to the LLM',
        ];
    }

    public function transformMcpResult(mixed $result): mixed
    {
        if (\is_array($result)) {
            unset($result['secret']);
        }

        return $result;
    }
}
