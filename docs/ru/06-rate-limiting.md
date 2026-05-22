# 06 — Rate limiting

Требует `symfony/rate-limiter` (это `composer suggest`). Бандл падает на сборке
контейнера с понятным сообщением, если метод объявил `#[Rpc\RateLimit]` без
установленного пакета.

## Базовое использование

```php
use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\RateLimitScope;

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
use JsonRpcServer\Attribute\RateLimitPolicy;

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
