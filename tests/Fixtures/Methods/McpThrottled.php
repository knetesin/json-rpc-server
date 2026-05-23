<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

#[Rpc\Method('test.mcp_throttled')]
#[Rpc\Mcp(description: 'Rate-limited tool used to verify MCP bypass.')]
#[Rpc\RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::GlobalScope)]
final class McpThrottled
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
