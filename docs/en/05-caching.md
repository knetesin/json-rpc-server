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
applied each time. Errors are **never** cached either.

## Scopes

A scope is an extra contributor to the cache key — typically used to partition
the cache per-user, per-IP, per-tenant, etc.

```php
#[Rpc\Method('user.profile')]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class GetMyProfile { /* … */ }
```

### Built-in scopes

- **`UserScope`** — partitions by the Symfony user identifier. Falls back to
  `anon` for unauthenticated calls.
- **`IpScope`** — partitions by client IP from `RequestStack`.

### Custom scopes

Implement `Knetesin\JsonRpcServerBundle\Cache\CacheScope`:

```php
final readonly class TenantScope implements CacheScope
{
    public function __construct(private TenantResolver $tenants) {}

    public function key(MethodMetadata $method, RpcRequest $request): string
    {
        return 'tenant:' . $this->tenants->current()?->getId() ?? 'public';
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
| `purge(method, params)` | Drop exactly one slot. | No |
| `purgeMethod(method)` | Drop everything cached under this method. | Yes |
| `purgeTags(tags, pool?)` | Drop everything stamped with these tags. | Yes |
| `purgeAll(pool?)` | Wipe the entire pool. | No |

All purges are info-logged so audit trails capture who/what cleared what.

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

```
rpc.cache | {method} | {scope.key()} | sha1({stable_sorted_params})
```

Stable-sorted means: associative params are sorted by key, list params keep
their order. Same JSON object regardless of key order → same hash.

Keys longer than 200 characters or containing reserved PSR-6 chars
(`{}()/\@:`) collapse into `rpc.{sha1}` — backend-safe and bounded.
