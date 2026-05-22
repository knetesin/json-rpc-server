# 05 — Кэширование

Добавьте `#[Rpc\Cache]` и результат метода живёт в PSR-6 пуле `ttl` секунд.
Hit'ы пропускают handler полностью.

## Базовое использование

```php
use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('weather.get')]
#[Rpc\Cache(ttl: 300)]                       // 5 минут
final class GetWeather
{
    public function __invoke(GetWeatherRequest $req): array { /* … */ }
}
```

Что делает бандл при вызове:

1. Строит cache-ключ из имени метода + scope + хэша параметров.
2. Ищет в пуле. Hit → возвращает значение, диспатчит
   `MethodInvocationStartedEvent` + `MethodInvocationCompletedEvent` (с
   `cacheHit: true`), handler не выполняется.
3. Miss → выполняет handler, нормализует результат, сохраняет, диспатчит события.

Notifications **никогда** не кэшируются — у них обычно side effects, которые
надо применять каждый раз. Ошибки **тоже** не кэшируются.

## Scope'ы

Scope — дополнительный contributor к cache-ключу. Типично используется для
партиционирования кэша per-user, per-IP, per-tenant.

```php
#[Rpc\Method('user.profile')]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class GetMyProfile { /* … */ }
```

### Встроенные scope-ы

- **`UserScope`** — партиция по Symfony user identifier. Откатывается на
  `anon` для unauthenticated вызовов.
- **`IpScope`** — партиция по client IP из `RequestStack`.

### Кастомный scope

Реализуйте `JsonRpcServer\Cache\CacheScope`:

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

Symfony autowire-ит. Ссылаетесь по FQCN:

```php
#[Rpc\Cache(ttl: 300, scope: TenantScope::class)]
```

## Pools

По дефолту бандл использует фреймворковский `cache.app`. Переопределить
глобально:

```yaml
json_rpc_server:
  cache:
    default_pool: 'app.short_lived'
```

Или per-method именованный pool:

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

Теги позволяют сбрасывать группы записей без сканирования:

```php
#[Rpc\Cache(ttl: 600, tags: ['user:42', 'profile'])]
```

Каждая cached запись автоматически также тегируется `rpc.method.{name}` — wipe
всего метода работает без явных тегов.

**Требует tag-aware пул.** Оберните любой PSR-6 адаптер:

```yaml
framework:
    cache:
        pools:
            app.long_lived:
                adapter: cache.adapter.redis
                tags: true                # включает tag-aware обёртку
```

Tag-операции на non-tag-aware пулах — silent no-op. Обычные `get`/`set` всегда
работают.

## API инвалидации

Инжектите `RpcCacheInvalidator` в коде приложения:

```php
use JsonRpcServer\Cache\RpcCacheInvalidator;

final class UpdateUserHandler
{
    public function __construct(private RpcCacheInvalidator $cache) {}

    public function handle(int $userId, array $changes): void
    {
        // ... apply changes ...

        // Сбросить конкретный slot:
        $this->cache->purge('user.profile', ['userId' => $userId]);

        // Или весь метод:
        $this->cache->purgeMethod('user.profile');

        // Или по тегу (cross-method):
        $this->cache->purgeTags(['user:'.$userId]);
    }
}
```

| Метод | Что делает | Нужен tag-aware? |
|---|---|---|
| `purge(method, params)` | Дропнуть один slot. | Нет |
| `purgeMethod(method)` | Дропнуть всё, кэшированное под этим методом. | Да |
| `purgeTags(tags, pool?)` | Дропнуть всё со стампом этих тегов. | Да |
| `purgeAll(pool?)` | Очистить весь пул. | Нет |

Все purge'ы пишутся в info-лог — годятся как audit-сигнал.

## CLI

```bash
# Дропнуть всё под user.profile
bin/console rpc:cache:clear user.profile

# Дропнуть по тегу
bin/console rpc:cache:clear --tag=user:42 --tag=tenant:acme

# Очистить весь пул
bin/console rpc:cache:clear --all --pool=long_lived
```

## Когда НЕ кэшировать

- **Мутирующие методы.** Кэшировать POST-подобные методы (create/update/delete)
  только если точно понимаете последствия — staleness больно укусит.
- **Per-call чувствительные данные.** Аккуратно с `scope:` — дефолтный no-scope
  значит "один слот для всех".
- **Streaming-методы.** Stream + Cache — compile-time error: stream нельзя
  переиграть из статичного blob'а.

## Как строится ключ

```
rpc.cache | {method} | {scope.key()} | sha1({stable_sorted_params})
```

Stable-sorted значит: ассоциативные params сортируются по ключу, list-params
сохраняют порядок. Один и тот же JSON-объект независимо от порядка ключей →
один и тот же хэш.

Ключи длиннее 200 символов или содержащие зарезервированные PSR-6 символы
(`{}()/\@:`) сворачиваются в `rpc.{sha1}` — backend-безопасно и ограничено.
