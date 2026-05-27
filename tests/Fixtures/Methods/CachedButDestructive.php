<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Pathological combination: caching paired with an explicit `readOnlyHint: false`.
 * The explicit attribute value must beat the Cache auto-derivation rule so
 * developers can always tighten what the bundle inferred.
 */
#[Rpc\Method('catalog.cachedButDestructive')]
#[Rpc\Cache(ttl: 30)]
#[Rpc\Mcp(
    description: 'Cached but explicitly flagged as mutating.',
    readOnlyHint: false,
    idempotentHint: false,
)]
final class CachedButDestructive
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
