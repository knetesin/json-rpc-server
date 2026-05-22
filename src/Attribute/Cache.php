<?php

declare(strict_types=1);

namespace JsonRpcServer\Attribute;

/**
 * Caches the result of a method for `ttl` seconds. On a cache hit the
 * handler does not run — the previously-stored result is returned directly.
 *
 * Cache key is composed from:
 *   - the method name,
 *   - the value returned by the `scope` contributor (omitted if scope is
 *     null — yielding one shared slot for all callers),
 *   - a stable hash of the request params (assoc keys sorted, list order
 *     preserved).
 *
 * `scope` is the FQCN of a service implementing
 * `JsonRpcServer\Cache\CacheScope`. The bundle ships two:
 *   - `UserScope`  — partitions by Symfony user identifier
 *   - `IpScope`    — partitions by client IP
 * Provide your own (country, tenant, locale, A/B segment) by implementing
 * the interface and pointing `scope:` at its class.
 *
 * `pool` is the name of a pool registered under `json_rpc_server.cache.pools` in bundle
 * config. Leave it null to use `json_rpc_server.cache.default_pool`.
 *
 * Notifications are not cached (they typically carry side effects).
 * Streaming methods cannot be cached — declaring both is a compile-time
 * error. Errors are not cached either: only successful invocations
 * populate the pool.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Cache
{
    /**
     * @param list<string> $tags additional cache tags attached on store. Combined with the
     *                           automatic tag `rpc.method:{name}` so {@see \JsonRpcServer\Cache\RpcCacheInvalidator}
     *                           can stamp out the whole method by name (or by your custom
     *                           label) without scanning. Requires a tag-aware pool —
     *                           tags are silently ignored on plain PSR-6 backends.
     */
    public function __construct(
        public readonly int $ttl,
        /** FQCN of a CacheScope. Null means a single shared slot. */
        public readonly ?string $scope = null,
        /** Name from json_rpc_server.cache.pools. Null = bundle default pool. */
        public readonly ?string $pool = null,
        public readonly array $tags = [],
    ) {
    }
}
