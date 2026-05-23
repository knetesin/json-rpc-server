# 14 — Context

`Context` — read-only объект, который бандл собирает per-call. Инжектится как
типизированный параметр:

```php
use Knetesin\JsonRpcServerBundle\Context\Context;

#[Rpc\Method('user.profile')]
final class GetProfile
{
    public function __invoke(Context $ctx): array
    {
        return [
            'who' => $ctx->user?->getUserIdentifier() ?? 'anonymous',
            'when' => $ctx->methodName,
            'requestId' => $ctx->requestId,
        ];
    }
}
```

## Форма

```php
final readonly class Context
{
    public string $methodName;
    public string $requestId;
    public ?\Symfony\Component\Security\Core\User\UserInterface $user;
    /** @var list<string> */
    public array $roles;

    public function hasRole(string $role): bool;
}
```

| Поле | Источник |
|---|---|
| `methodName` | Имя JSON-RPC метода, который вызывается. |
| `requestId` | Первое непустое: кэш `_rpc_request_id` в атрибутах → сконфигурированный request-id header (дефолт `X-Request-Id`) → свежесгенерированный `bin2hex(random_bytes(8))` (16 hex символов). |
| `user` | Текущий `UserInterface` из token storage, или `null` если anonymous / нет security-core. |
| `roles` | Список granted role-имён из текущего токена, или пустой. |
| `hasRole($r)` | Удобство для `in_array($r, $roles, true)`. |

`requestId` **кэшируется** обратно в атрибуты HTTP-запроса после первой
резолюции. В batched JSON-RPC вызове (5 методов в одном HTTP-запросе), все 5
инстансов `Context` шарят один `requestId` — полезно для коррелирования логов
и audit entries через batch.

## Установка request id извне

Бандл читает сконфигурированный HTTP-заголовок на каждый запрос — по умолчанию
`X-Request-Id`. API gateway или load balancer могут прокинуть свой
correlation id end-to-end без кода в приложении:

```
X-Request-Id: 9f4a-mobile-…
```

Сменить имя заголовка (например на `Trace-Id`) в конфиге:

```yaml
json_rpc_server:
    context:
        request_id_header: 'Trace-Id'   # '' выключает чтение заголовка совсем
```

Если ваше приложение производит id из Symfony-listener'а (а не через HTTP
header) — выставьте его в атрибут запроса до того как бандл прочитает:

```php
// В EventListener на kernel.request, ранний priority:
$request->attributes->set('_rpc_request_id', $traceId);
```

Бандл тогда использует это значение вместо генерации нового.

## Когда `Context::$user` это null

- Нет активного HTTP-запроса (например, CLI worker зовёт диспатчер напрямую)
- `symfony/security-core` не установлен
- Анонимный запрос (нет firewall token'а)
- `getUser()` у токена не возвращает `UserInterface`

Handler'ы должны трактовать null user как "anonymous", не как "logged in
without identifier".

## Отличия vs `RpcRequest`

| | `Context` | `RpcRequest` |
|---|---|---|
| Что это | Per-call session info (кто, когда, какой метод) | Сырой JSON-RPC envelope (`id`, `method`, `params`, `isNotification`) |
| Когда | Нужны user / roles / request id | Нужно программно инспектировать params или форвардить |
| Mutable? | Нет | Нет |

Можно инжектить оба:

```php
public function __invoke(MyRequest $req, Context $ctx, RpcRequest $envelope): array
{
    if ($envelope->isNotification) {
        // …
    }
    if ($ctx->hasRole('ROLE_ADMIN')) {
        // …
    }
}
```

## Внутри cache / rate-limit scope'ов

`Context` строится один раз на dispatch и переиспользуется. Cache и rate-limit
scope'ы читают из тех же `RequestStack` и `TokenStorage` напрямую — они
согласованы с тем, что возвращает `Context::$user`.

Например: rate-limit `scope: User` и `Context::$user` резолвятся в один и тот
же `getUserIdentifier()` — нет риска "rate-limit'ило как user X, audit'нуло
как user Y" внутри запроса.

## Паттерн логирования

Распространённый паттерн: префиксовать каждую log-строку в handler'ах
через `requestId`.

```php
public function __invoke(MyRequest $req, Context $ctx): array
{
    $this->logger->info('Processing request', [
        'request_id' => $ctx->requestId,
        'method' => $ctx->methodName,
        'user' => $ctx->user?->getUserIdentifier(),
    ]);
    // …
}
```

Для batch-операций все entry шарят `requestId`, grep находит их вместе.

Monolog-processor может это сделать автоматически — скармливаете ему
`RequestStack` и читаете `_request_id` из атрибутов текущего запроса.
