<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\RateLimitScope;

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
