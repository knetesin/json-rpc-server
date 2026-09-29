<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Cache;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;

/**
 * One dimension to partition a cached method by — point at it via
 * `#[Rpc\Cache(scope: MyScope::class)]`.
 *
 * The bundle ships two implementations:
 *   - `Scope\UserScope` — partition by Symfony user identifier
 *   - `Scope\IpScope`   — partition by client IP
 *
 * Implement your own for anything else (country, tenant, locale, A/B
 * segment). Resolution goes through the container by service id —
 * usually the FQCN.
 *
 * Return a short, stable string that captures the dimension you want to
 * vary on:
 *
 *   return 'country:' . $this->geoLocator->resolveCountry();
 *   return 'tenant:'  . $this->tenantContext->id();
 *
 * Any string works: keys of scoped entries are always hashed. The value is
 * also what `RpcCacheInvalidator::purge(..., $scopeKey)` expects to purge
 * another owner's entry.
 */
interface CacheScope
{
    public function key(MethodMetadata $method, RpcRequest $request): string;
}
