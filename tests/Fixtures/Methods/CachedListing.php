<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Cached + MCP-exposed: readOnlyHint and idempotentHint must be auto-derived
 * from `#[Rpc\Cache]` since neither is set explicitly on `#[Rpc\Mcp]`.
 */
#[Rpc\Method('catalog.cachedListing')]
#[Rpc\Cache(ttl: 30)]
#[Rpc\Mcp(description: 'Cached product listing.')]
final class CachedListing
{
    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        return [['sku' => 'A1'], ['sku' => 'B2']];
    }
}
