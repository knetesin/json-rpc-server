# 05 — Caching

Add `#[Rpc\Cache]` and method results live in a PSR-6 pool for `ttl` seconds.
Hits skip the handler entirely.

## Basic use

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('weather.get')]
#[Rpc\Cache(ttl: 300)]                       // 5 minutes
final class GetWeather
{
    public function __invoke(GetWeatherRequest $req): array { /* … */ }
}
```

What the bundle does on call:

1. Build a cache key from method name + scope + params hash.
2. Look it up in the pool. Hit → return the stored value, fire
   `MethodInvocationStartedEvent` + `MethodInvocationCompletedEvent` (with
   `cacheHit: true`), skip the handler.
3. Miss → call the handler, normalize the result, store it, dispatch events.

Notifications are **never** cached — they typically carry side effects you want
applied each time. Errors are **never** cached either, and neither are results
that cannot be JSON-encoded with the configured `json.encode_flags` (invalid
UTF-8, `NAN`/`INF`): the call answers -32603 and the next call runs the handler
again instead of replaying the failure until the TTL expires.

## Method guards and cache lookups

When at least one [method guard](./04-security.md#method-guards) is
registered, the dispatcher resolves and validates arguments **before** the
cache lookup — so a hit also pays for resolution/validation and runs the
guards. Invalid params fail with `-32602` even when a cached entry exists
for that key (e.g. a constraint that depends on the current date). With no
guards registered, the order is
unchanged: the cache lookup happens first, and a hit skips argument
resolution entirely.

## Scopes

A scope is an extra contributor to the cache key — typically used to partition
the cache per-user, per-IP, per-tenant, etc.

```php
#[Rpc\Method('user.profile')]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class GetMyProfile { /* … */ }
```

### Built-in scopes

- **`UserScope`** — partitions by the Symfony user identifier. Scope key:
  `user:{userIdentifier}` for authenticated calls, `guest` otherwise.
- **`IpScope`** — partitions by client IP from `RequestStack`. Scope key:
  `ip:{ip}`.

A custom scope's key is whatever its `key()` returns. Scope keys matter when
you purge another caller's entry — see [Invalidation API](#invalidation-api).

### Custom scopes

Implement `Knetesin\JsonRpcServerBundle\Cache\CacheScope`:

```php
final readonly class TenantScope implements CacheScope
{
    public function __construct(private TenantResolver $tenants) {}

    public function key(MethodMetadata $method, RpcRequest $request): string
    {
        return 'tenant:' . ($this->tenants->current()?->getId() ?? 'public');
    }
}
```

Symfony autowires it. Reference by FQCN:

```php
#[Rpc\Cache(ttl: 300, scope: TenantScope::class)]
```

## Pools

By default the bundle uses the framework's `cache.app`. Override globally:

```yaml
json_rpc_server:
  cache:
    default_pool: 'app.short_lived'
```

Or use a named pool per method:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            app.long_lived:
                adapter: cache.adapter.redis
                default_lifetime: 86400

# config/packages/json_rpc_server.yaml
json_rpc_server:
  cache:
    pools:
      long_lived: app.long_lived          # alias → service id
```

```php
#[Rpc\Cache(ttl: 86400, pool: 'long_lived')]
```

## Tags

Tags let you wipe groups of cache entries without scanning:

```php
#[Rpc\Cache(ttl: 600, tags: ['user:42', 'profile'])]
```

Every cached item is also automatically tagged with `rpc.method.{name}` so a
method-wide flush works without explicit tagging.

**Requires a tag-aware pool.** Wrap any plain PSR-6 adapter:

```yaml
framework:
    cache:
        pools:
            app.long_lived:
                adapter: cache.adapter.redis
                tags: true                # enables tag-aware wrapping
```

Tag operations on non-tag-aware pools silently no-op. Plain `get`/`set` always
works.

## Invalidation API

Inject `RpcCacheInvalidator` to clear entries from application code:

```php
use Knetesin\JsonRpcServerBundle\Cache\RpcCacheInvalidator;

final class UpdateUserHandler
{
    public function __construct(private RpcCacheInvalidator $cache) {}

    public function handle(int $userId, array $changes): void
    {
        // ... apply changes ...

        // Clear the specific slot:
        $this->cache->purge('user.profile', ['userId' => $userId]);

        // Or wipe everything for this method:
        $this->cache->purgeMethod('user.profile');

        // Or by tag (cross-method):
        $this->cache->purgeTags(['user:'.$userId]);
    }
}
```

| Method | Effect | Needs tag-aware? |
|---|---|---|
| `purge(method, params, scopeKey?)` | Drop exactly one slot. | No |
| `purgeMethod(method)` | Drop everything cached under this method. | Yes |
| `purgeTags(tags, pool?)` | Drop everything stamped with these tags. | Yes |
| `purgeAll(pool?)` | Wipe the entire pool. | No |

All purges are info-logged so audit trails capture who/what cleared what.

### Purging a scoped entry

For a method with a `scope:`, the slot also depends on whose entry it is.
Without `scopeKey`, `purge()` computes the scope for the **current caller** —
fine when users invalidate their own data, wrong when an admin (or a
background job, which has no user or IP) edits someone else's: the caller's
own slot is purged and the owner keeps the stale entry. Pass the owner's
scope key instead:

```php
// Admin updated alice's profile — drop alice's cached copy, not the admin's.
$this->cache->purge('user.profile', ['userId' => 42], 'user:alice');

// IpScope / custom scopes: the value their key() produces for the owner.
$this->cache->purge('geo.lookup', null, 'ip:203.0.113.7');
```

Passing a `scopeKey` for a method **without** a cache scope throws
`\InvalidArgumentException` — such a method has a single shared slot, so a
scope key there is a mistake; call `purge(method, params)` instead. To drop
every owner's entry at once use `purgeMethod()` or a tag.

## CLI

```bash
# Drop everything under user.profile
bin/console rpc:cache:clear user.profile

# Drop by tag
bin/console rpc:cache:clear --tag=user:42 --tag=tenant:acme

# Wipe a specific pool
bin/console rpc:cache:clear --all --pool=long_lived
```

## When not to cache

- **Mutating methods.** Cache POST-like methods (create/update/delete) only
  if you really know what you're doing — staleness will bite.
- **Per-call sensitive data.** Mix with `scope:` carefully; the default
  no-scope means "single slot shared by everyone".
- **Streaming methods.** Stream + Cache is rejected at compile time — streams
  can't be replayed from a static blob.

## How keys are built

Methods without a scope get a readable key:

```
{key_prefix}.{method}.sha1({stable_sorted_params})
e.g. rpc.cache.weather.get.52adfcb52fb68d9bf6baa00f81684b77e3263ae9   ({"city": "Berlin"})
```

Stable-sorted means: associative params are sorted by key, list params keep
their order. Same JSON object regardless of key order → same hash.

If that key is longer than `cache.max_readable_key_length` (default 200) or
contains characters outside `[A-Za-z0-9_.-]` (e.g. a custom `key_prefix` with
reserved PSR-6 chars `{}()/\@:`), it collapses into the hashed form.

Entries of **scoped** methods always use the hashed form — scope keys are
arbitrary strings, and a readable join could not tell method `a.b` from
method `a` with scope key `b`:

```
{hash_prefix}.sha1(serialize([{key_prefix}, {method}, {scope key}, sha1({stable_sorted_params})]))
e.g. rpc.108c12fa023dc0cdaa4b02d5b78350e9873130fd   (user.profile, user:alice, {"userId": 42})
```

`key_prefix` defaults to `rpc.cache`, `hash_prefix` to `rpc`. `purge()`,
`purgeMethod()` and tags address the same keys that calls read and write.

Upgrading to 1.7 changes every cache key — readable and hashed ones alike,
and `UserScope` now keys guests as `guest` instead of `user:anon` — so the
cache starts cold once; old entries expire by TTL.
