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

## Exempting requests (extension point)

Sometimes a limit should apply to everyone *except* a few callers — verified
search-engine crawlers, your own internal IP ranges, platform health checks.
Rather than replacing `RateLimitChecker`, implement
`RateLimitBypassInterface` in your app:

```php
namespace App\Rpc;

use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitBypassInterface;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class InternalNetworkBypass implements RateLimitBypassInterface
{
    public function __construct(private RequestStack $requestStack) {}

    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool
    {
        $ip = $this->requestStack->getMainRequest()?->getClientIp();

        return null !== $ip && \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, ['10.0.0.0/8']);
    }
}
```

`RateLimitChecker` consults every implementation **before** consuming a token.
The first voter to return `true` short-circuits the check — the method runs as
if it had no rate limit. Returning `false` defers to the next voter and,
ultimately, to the attribute's normal enforcement.

Key points:

- **Auto-wired.** Any service implementing the interface is auto-tagged
  (`registerForAutoconfiguration`) and collected — no manual tag needed.
- **Bypass-only.** A voter can *lift* a limit but cannot tighten one or add a
  limit where no `#[Rpc\RateLimit]` exists. (If you ever need that, vote on a
  richer decision type — but start here.)
- **`MethodMetadata` is passed in,** so a voter can scope itself to specific
  methods, prefixes, or roles without extra config.
- **Keep it cheap** — it runs on every rate-limited call. Cache anything slow
  (DNS, external lookups).
- Multiple voters compose as an OR chain (bot **or** internal IP **or** health
  check).

### Example: skip the limit for verified search-engine crawlers

A common need: keep a public method throttled for users, but let Googlebot /
YandexBot / Bingbot crawl freely. **Never trust the User-Agent alone** — it is
trivially spoofed. The reliable check is *forward-confirmed reverse DNS*
(FCrDNS): reverse-resolve the client IP, confirm the hostname belongs to the
crawler's domain, then forward-resolve that hostname and confirm it maps back to
the same IP. User-Agent is used only as a *cheap negative filter* so normal
traffic never triggers a DNS lookup.

```php
// src/Rpc/SearchEngineBotVerifier.php
namespace App\Rpc;

use Psr\Cache\CacheItemPoolInterface;

final readonly class SearchEngineBotVerifier
{
    public function __construct(private CacheItemPoolInterface $cache) {}

    /** Forward-confirmed hostname for the IP, or null. Cached per IP. */
    public function confirmedHost(string $ip): ?string
    {
        $item = $this->cache->getItem('botptr.'.str_replace([':', '.'], '_', $ip));
        if ($item->isHit()) {
            return $item->get(); // string|null
        }

        $host = $this->resolve($ip);
        // Verified bots cached for a day; misses kept short so a flood of
        // forged PTRs can't poison the pool for long.
        $item->set($host)->expiresAfter(null !== $host ? 86400 : 600);
        $this->cache->save($item);

        return $host;
    }

    private function resolve(string $ip): ?string
    {
        $host = @gethostbyaddr($ip);
        if (false === $host || $host === $ip) {
            return null;
        }
        $host = rtrim(strtolower($host), '.');

        // forward-confirm: the hostname must resolve back to the original IP
        foreach (@dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [] as $r) {
            if (($r['ip'] ?? null) === $ip || ($r['ipv6'] ?? null) === $ip) {
                return $host;
            }
        }

        return null;
    }
}
```

```php
// src/Rpc/SearchEngineBotBypass.php
namespace App\Rpc;

use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitBypassInterface;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SearchEngineBotBypass implements RateLimitBypassInterface
{
    /** engine => [User-Agent markers, allowed host suffixes] */
    private const ENGINES = [
        'google' => [['Googlebot', 'Storebot-Google', 'GoogleOther'], ['.googlebot.com', '.google.com']],
        'yandex' => [['YandexBot', 'YandexImages'], ['.yandex.ru', '.yandex.com', '.yandex.net']],
        'bing'   => [['bingbot', 'BingPreview'], ['.search.msn.com']],
    ];

    /** @param list<string> $methods methods this bypass applies to */
    public function __construct(
        private SearchEngineBotVerifier $verifier,
        private RequestStack $requestStack,
        private array $methods = [],
    ) {}

    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool
    {
        // cheap #1: not one of our methods → normal limit applies
        if (!\in_array($method->name, $this->methods, true)) {
            return false;
        }

        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return false;
        }

        // cheap #2: UA doesn't even claim to be a bot → no DNS at all
        $expectedSuffixes = $this->matchUserAgent($request->headers->get('User-Agent', ''));
        if (null === $expectedSuffixes) {
            return false;
        }

        $ip = $request->getClientIp();
        if (null === $ip) {
            return false;
        }

        // expensive, but only for "bot-like" UAs and cached per IP
        $host = $this->verifier->confirmedHost($ip);
        if (null === $host) {
            return false; // PTR not confirmed → spoofed UA
        }

        // UA must match the real engine: UA=Googlebot but host=*.yandex → deny
        foreach ($expectedSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null expected host suffixes, or null */
    private function matchUserAgent(string $ua): ?array
    {
        foreach (self::ENGINES as [$needles, $suffixes]) {
            foreach ($needles as $needle) {
                if (false !== stripos($ua, $needle)) {
                    return $suffixes;
                }
            }
        }

        return null;
    }
}
```

```yaml
# config/services.yaml — SearchEngineBotVerifier autowires cache.app;
# the bypass is auto-tagged. Only the method list needs declaring.
services:
    App\Rpc\SearchEngineBotBypass:
        arguments:
            $methods: ['search.query', 'catalog.search', 'suggest.complete']
```

Request flow: not your method → no work; UA isn't bot-like → no DNS; IP cached →
instant; otherwise one FCrDNS lookup per IP per day. A spoofed User-Agent never
wins because the bypass requires DNS confirmation.

> **Behind a proxy/CDN?** `getClientIp()` only honours `X-Forwarded-For` with
> `framework.trusted_proxies` configured — otherwise you'd be verifying the load
> balancer's IP. For engines that publish their ranges (Google, Bing) you can
> skip DNS entirely and match against the published CIDR lists with
> `IpUtils::checkIp()`; Yandex doesn't publish full ranges, so FCrDNS stays the
> fallback there.

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
