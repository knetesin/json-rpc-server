# 04 — Безопасность и роли

Два уровня:

1. **Аутентификация** — кто вызывает. Дело вашего Symfony firewall.
2. **Авторизация** — что можно вызвать. Дело per-method `roles`.

Бандл занимается авторизацией. Аутентификация остаётся на firewall'е; бандл
никогда не смотрит креды напрямую.

## Публичные методы

По умолчанию: если опустить `roles` — диспатчер пропускает авторизацию совсем:

```php
#[Rpc\Method('public.ping')]
final class Ping
{
    public function __invoke(): array { return ['pong' => true]; }
}
```

Анонимные запросы проходят, **при условии что ваш firewall тоже их пускает
на `/rpc`.**

> **Secure-by-default режим.** Задайте `security.default_roles`
> (см. [Configuration reference](./13-configuration.md#securitydefault_roles--public_prefixes--public_methods--prefix_roles)) —
> тогда любой метод без явного `roles:` наследует эти роли, а анонимными
> остаются только перечисленные в `public_prefixes` / `public_methods`.
> `prefix_roles` (напр. `admin.* → ROLE_ADMIN`) задаёт дефолтные роли точечно
> по префиксу имён, без `roles:` на каждом хендлере.

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

## Method guards

Роли выражают статичные правила доступа ("есть `ROLE_ADMIN`"). Некоторым
правилам нужны резолвнутые аргументы — "может ли этот вызывающий редактировать
*именно эту* группу" — для этого `MethodGuardInterface`:

```php
namespace Knetesin\JsonRpcServerBundle\Security;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;

interface MethodGuardInterface
{
    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void;
}
```

Реализуйте интерфейс в сервисе — он авто-тегируется
`json_rpc_server.method_guard`, ручного wiring не нужно. `$args` — резолвнутый
список аргументов `__invoke()`, ключами по именам параметров, в порядке
объявления (DTO-инстанс или скалярные значения) — те же инстансы, что получит
handler, так что относитесь к ним как к read-only. Бросайте исключение, чтобы
отказать в доступе.

### Регистрация и порядок

Можно зарегистрировать несколько guard'ов; они выполняются в порядке
приоритета тега, больший приоритет — раньше:

```php
#[AsTaggedItem(priority: 10)]
final class GroupAccessGuard implements MethodGuardInterface { /* … */ }
```

Первое брошенное исключение останавливает цепочку — guard'ы после него не
выполняются.

Без autoconfiguration повесьте на сервис тег `json_rpc_server.method_guard`
сами, с опциональным `priority` — в конфиге сервисов или из любого compiler
pass:

```yaml
services:
    App\Security\GroupAccessGuard:
        tags:
            - { name: json_rpc_server.method_guard, priority: 10 }
```

Класс сервиса с этим тегом обязан реализовывать `MethodGuardInterface`, иначе
сборка контейнера падает.

### Где в пайплайне выполняются guard'ы

Roles → rate limit → резолв аргументов + валидация → **guards** → cache
lookup → handler. Guard'ы выполняются на каждом пути диспатча: одиночный
вызов, каждый элемент batch'а, notifications (отказ не даёт ответа — так
требует JSON-RPC для notifications), streaming (до вызова handler'а/итератора;
pre-stream отказ возвращает обычный HTTP 400 JSON envelope — см.
[Стриминг](./07-streaming.md)), MCP `tools/call` (HTTP 200 с
`isError: true`, по конвенции MCP), и sub-вызовы параллельного batch'а (каждый
идёт через тот же диспатчер). Guard'ы выполняются до cache lookup, поэтому
**cache hit никогда их не пропускает** — см.
[Method guards и cache lookup](./05-caching.md#method-guards-и-cache-lookup).

При включённом параллельном batch'е каждый элемент выполняется как внутренний
HTTP sub-request обратно в то же приложение. Guard'ы выполняются и там, но
HTTP-запрос, который они видят через `RequestStack`, несёт только заголовки из
`parallel_batch.forward_headers`, а соединение приходит от самого сервера,
поэтому client IP — это IP сервера, а если приложение доверяет своему адресу
как proxy — то, что написано в пересланном `X-Forwarded-For`. Принимайте
решения в guard'ах по security token и RPC-аргументам, а не по произвольным
заголовкам или client IP.

### Ошибки

`RpcException`, брошенный из guard'а, доходит до клиента со своим кодом,
сообщением и `rpcData()`. Любое другое исключение становится
`-32603 Internal error` (и логируется). В обоих случаях срабатывает
`MethodInvocationFailedEvent`. При `http_status.enabled` HTTP-статус следует
за кодом ошибки — см. [Ошибки](./10-errors.md#http-статусы); например,
дефолтный код `NotFoundException` -32002 маппится на 404, дефолтный код
`AccessDeniedException` -32001 — на 400.

### Чтение атрибутов handler'а

`$meta->getAttributes(SomeAttribute::class)` возвращает инстансы атрибутов
**класса** handler'а (не `__invoke()`, не унаследованные от родительских
классов), собранные на этапе компиляции контейнера — без runtime reflection.
Собираются только когда зарегистрирован хотя бы один guard; иначе
`$meta->attributes` остаётся пустым. Собственные атрибуты бандла `Rpc\*` и
`Symfony\Component\DependencyInjection\Attribute\*` исключаются; атрибут,
класс которого не загружен, пропускается.

Любой другой атрибут на уровне класса должен быть инстанцируем (валидный
target, правила repeatable, конструктор) и принимать в конструкторе только
скалярные, array, enum или null аргументы — контейнер должен уметь его
задампить, — а ключи массивов в этих аргументах не должны содержать `%`
(контейнер прочитает их как `%parameter%` placeholder). Иначе сборка
контейнера падает с сообщением, называющим метод и атрибут.

### Пример

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Requires
{
    public function __construct(
        public Permission $permission,
        public string $on,
    ) {}
}
```

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('group.rename')]
#[Requires(Permission::GroupEdit, on: 'groupId')]
final class RenameGroup
{
    public function __invoke(RenameGroupRequest $request): void { /* … */ }
}
```

```php
use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface;

final class RequiresPermissionGuard implements MethodGuardInterface
{
    public function __construct(private PermissionChecker $permissions) {}

    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void
    {
        foreach ($meta->getAttributes(Requires::class) as $requires) {
            $groupId = $args[$requires->on] ?? $args['request']->{$requires->on};
            if (!$this->permissions->has($requires->permission, $groupId)) {
                throw new AccessDeniedException('Group access denied');
            }
        }
    }
}
```

`AccessDeniedException` принимает `(string $message, int $rpcCode = -32001)` —
передайте любой JSON-RPC код ошибки, который должен увидеть клиент (при
`http_status.enabled` учитывайте маппинг выше), или бросьте свой подкласс
`RpcException` с кастомным `rpcData()`.

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
