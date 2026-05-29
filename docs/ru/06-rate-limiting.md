# 06 — Rate limiting

Требует `symfony/rate-limiter` (это `composer suggest`). Бандл падает на сборке
контейнера с понятным сообщением, если метод объявил `#[Rpc\RateLimit]` без
установленного пакета.

## Базовое использование

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

#[Rpc\Method('report.heavy')]
#[Rpc\RateLimit(limit: 10, intervalSec: 60, scope: RateLimitScope::User)]
final class HeavyReport { /* … */ }
```

Одиннадцать вызовов одного юзера в минуту → одиннадцатый падает с -32003
`RateLimitExceeded`, с `Retry-After` в HTTP-заголовках **и** в data
JSON-RPC-ошибки:

```json
{
  "error": {
    "code": -32003,
    "message": "Rate limit exceeded for report.heavy",
    "data": {"retryAfter": 42}
  }
}
```

HTTP-ответ также несёт `Retry-After: 42` — middleware клиента может бэкоффить
без парсинга body.

## Scope'ы

Где партиционируется счётчик:

| Scope | Ключ счётчика | Use case |
|---|---|---|
| `RateLimitScope::User` (default) | Symfony user identifier; `anon` для гостей. | Per-user fairness. |
| `RateLimitScope::Ip` | Client IP из `RequestStack`; `unknown` если нет. | Тротлинг анонимного трафика. |
| `RateLimitScope::GlobalScope` | Один общий счётчик на метод. | Защита downstream-сервисов. |

```php
#[Rpc\RateLimit(limit: 100, intervalSec: 60, scope: RateLimitScope::Ip)]
```

## Политики

Алгоритм. Те же `limit` и `intervalSec`, разное поведение:

| Политика | Поведение | Когда |
|---|---|---|
| `FixedWindow` (default) | Счётчик сбрасывается на границах окна. Дешёвая. На стыке окон можно получить `2×limit`. | Большинство дефолтов. |
| `SlidingWindow` | Взвешивает предыдущее окно пропорционально — без edge spike. Чуть больше хранилища. | Жёсткие SLA на границе. |
| `TokenBucket` | Ведро на `limit` токенов, refill `limit/intervalSec` в секунду. Burst до `limit`, потом steady-state. | Human/UI трафик с естественными burst'ами. |
| `NoLimit` | Выключено. Атрибут документирует намерение, но не enforce'ит. | Per-env переключения, тесты. |

```php
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitPolicy;

#[Rpc\RateLimit(
    limit: 5,
    intervalSec: 60,
    scope: RateLimitScope::User,
    policy: RateLimitPolicy::TokenBucket,   // burst до 5, потом 5 в минуту
)]
```

### Как `limit`/`intervalSec` мапятся на политику

- **FixedWindow / SlidingWindow** — `limit` вызовов разрешено в любом окне
  `intervalSec` секунд.
- **TokenBucket** — размер ведра = `limit` (также max instantaneous burst).
  Refill: `limit` токенов за `intervalSec`. Steady-state: `limit/intervalSec`
  в секунду.
- **NoLimit** — оба значения игнорируются; ничего не enforce'ится.

## MCP-трафик

`#[Rpc\RateLimit]` применяется к `/rpc` вызовам. Для `/mcp/call` — **выключен
по дефолту**: MCP-трафик обычно идёт от доверенного внутреннего агента (Claude
Desktop, ваш собственный server-side LLM). Включите для публичного MCP:

```yaml
json_rpc_server:
  mcp:
    apply_rate_limit: true
```

## Storage

Default storage — `cache.app`. Для использования другого пула — оберните свой
`RateLimiterFactory` и замените `RateLimitChecker` через стандартные Symfony
DI overrides.

## Исключения из лимита (точка расширения)

Иногда лимит должен действовать на всех, *кроме* нескольких клиентов —
проверенных поисковых краулеров, ваших внутренних IP, health-check'ов
платформы. Вместо замены `RateLimitChecker` реализуйте в своём проекте
`RateLimitBypassInterface`:

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

`RateLimitChecker` опрашивает все реализации **перед** списанием токена. Первый
voter, вернувший `true`, замыкает проверку — метод выполняется как без лимита.
`false` передаёт решение следующему voter'у и, в итоге, штатному enforcement'у
атрибута.

Ключевое:

- **Автовайринг.** Любой сервис, реализующий интерфейс, автоматически тегируется
  (`registerForAutoconfiguration`) и собирается — ручной тег не нужен.
- **Только bypass.** Voter может *снять* лимит, но не ужесточить и не навесить
  туда, где нет `#[Rpc\RateLimit]`. (Если понадобится — голосуйте более богатым
  типом решения, но начните с этого.)
- **`MethodMetadata` передаётся внутрь** — voter сам ограничивается нужными
  методами, префиксами или ролями без отдельного конфига.
- **Держите проверку дешёвой** — она бежит на каждый rate-limited вызов.
  Кэшируйте всё медленное (DNS, внешние запросы).
- Несколько voter'ов складываются в OR-цепочку (бот **или** внутренний IP
  **или** health-check).

### Пример: снять лимит для проверенных поисковых краулеров

Частая задача: метод тротлится для пользователей, но Googlebot / YandexBot /
Bingbot ходят свободно. **Нельзя доверять только User-Agent** — он тривиально
подделывается. Надёжная проверка — *forward-confirmed reverse DNS* (FCrDNS):
обратный резолв IP, проверка что hostname принадлежит домену краулера, затем
прямой резолв этого hostname и сверка, что он указывает обратно на тот же IP.
User-Agent используется только как *дешёвый негативный фильтр*, чтобы обычный
трафик вообще не доходил до DNS.

```php
// src/Rpc/SearchEngineBotVerifier.php
namespace App\Rpc;

use Psr\Cache\CacheItemPoolInterface;

final readonly class SearchEngineBotVerifier
{
    public function __construct(private CacheItemPoolInterface $cache) {}

    /** Подтверждённый по FCrDNS hostname для IP, либо null. Кэшируется по IP. */
    public function confirmedHost(string $ip): ?string
    {
        $item = $this->cache->getItem('botptr.'.str_replace([':', '.'], '_', $ip));
        if ($item->isHit()) {
            return $item->get(); // string|null
        }

        $host = $this->resolve($ip);
        // Подтверждённых ботов держим сутки, промахи — коротко, чтобы поток
        // поддельных PTR не отравил пул надолго.
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

        // forward-confirm: hostname должен резолвиться обратно в исходный IP
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
    /** engine => [маркеры User-Agent, разрешённые host-суффиксы] */
    private const ENGINES = [
        'google' => [['Googlebot', 'Storebot-Google', 'GoogleOther'], ['.googlebot.com', '.google.com']],
        'yandex' => [['YandexBot', 'YandexImages'], ['.yandex.ru', '.yandex.com', '.yandex.net']],
        'bing'   => [['bingbot', 'BingPreview'], ['.search.msn.com']],
    ];

    /** @param list<string> $methods методы, для которых работает байпасс */
    public function __construct(
        private SearchEngineBotVerifier $verifier,
        private RequestStack $requestStack,
        private array $methods = [],
    ) {}

    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool
    {
        // дешёвая #1: не наш метод → работает обычный лимит
        if (!\in_array($method->name, $this->methods, true)) {
            return false;
        }

        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return false;
        }

        // дешёвая #2: UA даже не притворяется ботом → DNS не трогаем
        $expectedSuffixes = $this->matchUserAgent($request->headers->get('User-Agent', ''));
        if (null === $expectedSuffixes) {
            return false;
        }

        $ip = $request->getClientIp();
        if (null === $ip) {
            return false;
        }

        // дорого, но только для "ботоподобных" UA и кэшируется по IP
        $host = $this->verifier->confirmedHost($ip);
        if (null === $host) {
            return false; // PTR не подтвердился → спуф UA
        }

        // UA должен совпасть с реальным движком: UA=Googlebot, host=*.yandex → отказ
        foreach ($expectedSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null ожидаемые host-суффиксы, либо null */
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
# config/services.yaml — SearchEngineBotVerifier автовайрит cache.app;
# сам байпасс автотегируется. Объявить нужно только список методов.
services:
    App\Rpc\SearchEngineBotBypass:
        arguments:
            $methods: ['search.query', 'catalog.search', 'suggest.complete']
```

Поток запроса: не ваш метод → нет работы; UA не похож на бота → нет DNS; IP в
кэше → мгновенно; иначе один FCrDNS-резолв на IP в сутки. Подделанный User-Agent
никогда не выигрывает, потому что байпасс требует подтверждения по DNS.

> **За прокси/CDN?** `getClientIp()` учитывает `X-Forwarded-For` только при
> настроенных `framework.trusted_proxies` — иначе будете верифицировать IP
> балансировщика. Для движков, публикующих свои диапазоны (Google, Bing),
> можно вообще не трогать DNS и сверять с опубликованными CIDR-списками через
> `IpUtils::checkIp()`; Yandex полных диапазонов не публикует, для него остаётся
> FCrDNS.

## Примеры

### Анонимный API rate-limit по IP

```php
#[Rpc\Method('search.public')]
#[Rpc\RateLimit(
    limit: 30,
    intervalSec: 60,
    scope: RateLimitScope::Ip,
)]
final class PublicSearch { /* … */ }
```

### Тяжёлый метод с burst'ами

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

Ведро = 3, refill 3 за час. Юзер может сделать 3 экспорта подряд, дальше
~20 минут на каждый следующий токен.

### Глобальный rate-limit к upstream'у

```php
#[Rpc\Method('translate.text')]
#[Rpc\RateLimit(
    limit: 100,
    intervalSec: 1,
    scope: RateLimitScope::GlobalScope,
)]
final class TranslateText { /* защищает квоту upstream API */ }
```

### Per-env отключение через config

```php
#[Rpc\RateLimit(
    limit: 10,
    intervalSec: 60,
    policy: RateLimitPolicy::NoLimit,    // намерение задокументировано
)]
```

Или оставить `FixedWindow` и переопределять `limit` через config per-env —
сейчас не bundled; подклассуйте и инжектьте свои значения.
