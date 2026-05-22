# 04 — Безопасность и роли

Два уровня:

1. **Аутентификация** — кто вызывает. Дело вашего Symfony firewall.
2. **Авторизация** — что можно вызвать. Дело per-method `roles`.

Бандл занимается авторизацией. Аутентификация остаётся на firewall'е; бандл
никогда не смотрит креды напрямую.

## Публичные методы

Опустите `roles` — диспатчер пропускает авторизацию совсем:

```php
#[Rpc\Method('public.ping')]
final class Ping
{
    public function __invoke(): array { return ['pong' => true]; }
}
```

Анонимные запросы проходят, **при условии что ваш firewall тоже их пускает
на `/rpc`.**

## Защищённые методы

```php
#[Rpc\Method('user.delete', roles: ['ROLE_ADMIN'])]
final class DeleteUser { /* … */ }
```

При вызове диспатчер дёргает `AuthorizationCheckerInterface::isGranted()` по
каждой роли. Нет роли → бросает `AccessDeniedException` (-32001).

Если `symfony/security-bundle` не установлен, а метод объявляет `roles` —
бандл падает на первом же вызове с понятным сообщением "install
symfony/security-bundle". Никакого silent bypass.

## Несколько ролей: any vs all

```php
// Any (default) — хотя бы одна роль.
#[Rpc\Method('billing.refund', roles: ['ROLE_SUPPORT', 'ROLE_ADMIN'])]

// All — все роли.
#[Rpc\Method(
    'compliance.export',
    roles: ['ROLE_ADMIN', 'ROLE_COMPLIANCE'],
    rolesMatch: RoleMatch::All,
)]
```

Поменять дефолт для методов, которые не указали `rolesMatch`:

```yaml
json_rpc_server:
  security:
    roles_match: all   # или 'any'
```

## Скрытие имён ролей в сообщениях об ошибке

По дефолту `AccessDenied` называет недостающие роли:

```
One of the following roles is required: ROLE_BILLING_INTERNAL_ADMIN
```

Удобно в dev. В prod некоторые команды считают role identifier'ы внутренними —
переверните флаг:

```yaml
json_rpc_server:
  security:
    expose_role_names: false
```

Теперь сообщение просто `Access denied`. HTTP body всё ещё несёт
`error.code: -32001`, просто без утечки.

## Конфигурация firewall

Бандл ничего не ставит со стороны firewall'а. Типичный сетап если `/rpc`
аутентифицируется через JWT:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        rpc:
            pattern: ^/rpc
            stateless: true
            jwt: ~
        # или другая ваша схема
```

То, что лежит в token storage как `UserInterface`, становится `Context::$user`
и питает `RoleMatch` проверки.

## Работа с пользователем внутри handler'а

```php
public function __invoke(MyRequest $req, Context $ctx): array
{
    $userId = $ctx->user?->getUserIdentifier();    // null для anon
    $isAdmin = $ctx->hasRole('ROLE_ADMIN');
    // …
}
```

`Context` read-only, per-call. См. [Context](./14-context.md).

## Cache scope'ы по пользователю

Если используется `#[Rpc\Cache]`, бандл поставляется с `UserScope` — кэш
ключится per user identifier:

```php
#[Rpc\Method('user.profile', roles: ['ROLE_USER'])]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class GetMyProfile { /* … */ }
```

См. [Кэширование](./05-caching.md#встроенные-scope-ы).

## Rate limiting по пользователю

`RateLimitScope::User` ключит счётчик rate limit'а по user identifier'у:

```php
#[Rpc\Method('billing.heavyReport', roles: ['ROLE_USER'])]
#[Rpc\RateLimit(limit: 5, intervalSec: 60, scope: RateLimitScope::User)]
final class HeavyReport { /* … */ }
```

Анонимные шарят слот `anon` — обычно это нужное поведение (троттлить аноним
жестко).

## Security-чеклист

- ✅ Firewall накрывает `/rpc`, `/mcp/call`, `/rpc/stream`
- ✅ Роли на каждом non-public методе, `rolesMatch: All` для критичных
- ✅ `expose_role_names: false` в проде
- ✅ Rate-limit анонимных endpoint'ов (`scope: Ip`)
- ✅ `max_request_size` — ваш максимум приемлемого payload'а (default 1 MB)
- ✅ MCP-трафик — если выставлен наружу, `mcp.apply_rate_limit: true`
