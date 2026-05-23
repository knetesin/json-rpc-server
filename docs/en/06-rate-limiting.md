# 06 — Rate limiting

Requires `symfony/rate-limiter` (a `composer suggest`). The bundle fails the
container build with a clear message if a method declares `#[Rpc\RateLimit]`
without the package installed.

## Basic use

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

#[Rpc\Method('report.heavy')]
#[Rpc\RateLimit(limit: 10, intervalSec: 60, scope: RateLimitScope::User)]
final class HeavyReport { /* … */ }
```

Eleven calls per user in one minute → eleventh fails with -32003
`RateLimitExceeded`, with `Retry-After` in the HTTP headers **and** the JSON-RPC
error data:

```json
{
  "error": {
    "code": -32003,
    "message": "Rate limit exceeded for report.heavy",
    "data": {"retryAfter": 42}
  }
}
```

The HTTP response also carries `Retry-After: 42` so client middleware can back
off without parsing the body.

## Scopes

Where the counter is partitioned:

| Scope | Counter key | Use case |
|---|---|---|
| `RateLimitScope::User` (default) | Symfony user identifier; `anon` for guests. | Protect per-user fairness. |
| `RateLimitScope::Ip` | Client IP from `RequestStack`; `unknown` if none. | Throttle anonymous traffic. |
| `RateLimitScope::GlobalScope` | One shared counter for the method. | Protect downstream services. |

```php
#[Rpc\RateLimit(limit: 100, intervalSec: 60, scope: RateLimitScope::Ip)]
```

## Policies

The underlying algorithm. Same `limit` and `intervalSec`, different behavior:

| Policy | Behavior | When to use |
|---|---|---|
| `FixedWindow` (default) | Counter resets at fixed boundaries. Cheap. Can allow `2×limit` across the boundary. | Most defaults. |
| `SlidingWindow` | Weights the previous window proportionally — no edge spike. Slightly more storage. | Strict SLAs at the edge. |
| `TokenBucket` | Bucket of `limit` tokens refills `limit/intervalSec` per second. Allows bursts up to `limit`, then steady-state. | Human/UI traffic where bursts are natural. |
| `NoLimit` | Disabled. The attribute documents intent but enforces nothing. | Per-env toggling, tests. |

```php
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitPolicy;

#[Rpc\RateLimit(
    limit: 5,
    intervalSec: 60,
    scope: RateLimitScope::User,
    policy: RateLimitPolicy::TokenBucket,   // burst up to 5, then 5 per min
)]
```

### How `limit`/`intervalSec` map per policy

- **FixedWindow / SlidingWindow** — `limit` calls allowed in any `intervalSec`-second window.
- **TokenBucket** — bucket size is `limit` (also max instantaneous burst). Refills `limit` tokens over `intervalSec`, so steady-state is `limit/intervalSec` per second.
- **NoLimit** — both values ignored; nothing enforced.

## MCP traffic

`#[Rpc\RateLimit]` applies to `/rpc` calls. For `/mcp/call` it's **off by
default** — MCP traffic typically comes from a trusted internal agent (Claude
Desktop, your own server-side LLM). Flip on for public MCP exposure:

```yaml
json_rpc_server:
  mcp:
    apply_rate_limit: true
```

## Storage

The default storage is `cache.app`. To use a different pool, wrap your own
`RateLimiterFactory` and replace the bundle's `RateLimitChecker` — overrideable
via standard Symfony DI overrides.

## Examples

### Anonymous API rate limit per IP

```php
#[Rpc\Method('search.public')]
#[Rpc\RateLimit(
    limit: 30,
    intervalSec: 60,
    scope: RateLimitScope::Ip,
)]
final class PublicSearch { /* … */ }
```

### Expensive method with bursts

```php
#[Rpc\Method('export.csv', roles: ['ROLE_USER'])]
#[Rpc\RateLimit(
    limit: 3,
    intervalSec: 3600,
    scope: RateLimitScope::User,
    policy: RateLimitPolicy::TokenBucket,
)]
final class ExportCsv { /* … */ }
```

Bucket = 3, refills 3 over an hour. User can do all 3 exports back-to-back,
then waits ~20 min per subsequent token.

### Global outbound rate limit

```php
#[Rpc\Method('translate.text')]
#[Rpc\RateLimit(
    limit: 100,
    intervalSec: 1,
    scope: RateLimitScope::GlobalScope,
)]
final class TranslateText { /* protects upstream API quota */ }
```

### Per-env disable via config (no recompile)

```php
#[Rpc\RateLimit(
    limit: 10,
    intervalSec: 60,
    policy: RateLimitPolicy::NoLimit,    // intent stays documented
)]
```

Or keep `FixedWindow` and override the limit per env via config — currently
not bundled; subclass and inject your own values for that.
