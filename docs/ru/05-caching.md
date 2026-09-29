# 05 — Кэширование

Добавьте `#[Rpc\Cache]` и результат метода живёт в PSR-6 пуле `ttl` секунд.
Hit'ы пропускают handler полностью.

## Базовое использование

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

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
надо применять каждый раз. Ошибки **тоже** не кэшируются, как и результаты,
которые нельзя закодировать в JSON с настроенными `json.encode_flags`
(невалидный UTF-8, `NAN`/`INF`): вызов отвечает -32603, а следующий вызов снова
выполняет handler, а не повторяет ошибку из кэша до истечения TTL.

## Method guards и cache lookup

Когда зарегистрирован хотя бы один [method guard](./04-security.md#method-guards),
диспатчер резолвит и валидирует аргументы **до** cache lookup — то есть hit
тоже платит за резолв/валидацию и прогоняет guard'ы. Невалидные params всё
равно падают с `-32602`, даже если для этого ключа уже есть закэшированная
запись (например, constraint, зависящий от текущей даты). Если guard'ов
нет, порядок не меняется: cache lookup идёт первым, и hit полностью
пропускает резолв аргументов.

## Scope'ы

Scope — дополнительный contributor к cache-ключу. Типично используется для
партиционирования кэша per-user, per-IP, per-tenant.

```php
#[Rpc\Method('user.profile')]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class GetMyProfile { /* … */ }
```

### Встроенные scope-ы

- **`UserScope`** — партиция по Symfony user identifier. Scope key:
  `user:{userIdentifier}` для authenticated вызовов, иначе `guest`.
- **`IpScope`** — партиция по client IP из `RequestStack`. Scope key:
  `ip:{ip}`.

Scope key кастомного scope — то, что возвращает его `key()`. Scope key нужен,
когда вы purge'ите чужую запись — см. [API инвалидации](#api-инвалидации).

### Кастомный scope

Реализуйте `Knetesin\JsonRpcServerBundle\Cache\CacheScope`:

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
use Knetesin\JsonRpcServerBundle\Cache\RpcCacheInvalidator;

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
| `purge(method, params, scopeKey?)` | Дропнуть один slot. | Нет |
| `purgeMethod(method)` | Дропнуть всё, кэшированное под этим методом. | Да |
| `purgeTags(tags, pool?)` | Дропнуть всё со стампом этих тегов. | Да |
| `purgeAll(pool?)` | Очистить весь пул. | Нет |

Все purge'ы пишутся в info-лог — годятся как audit-сигнал.

### Purge записи со scope

У метода со `scope:` slot зависит ещё и от того, чья это запись. Без
`scopeKey` `purge()` вычисляет scope **текущего вызывающего** — это верно,
когда пользователь инвалидирует свои данные, и неверно, когда админ (или
фоновая задача, у которой нет ни user, ни IP) правит чужие: purge'ится slot
самого вызывающего, а у владельца остаётся устаревшая запись. Передайте scope
key владельца:

```php
// Админ обновил профиль alice — дропаем кэш alice, а не админа.
$this->cache->purge('user.profile', ['userId' => 42], 'user:alice');

// IpScope / кастомные scope'ы: значение, которое их key() даёт для владельца.
$this->cache->purge('geo.lookup', null, 'ip:203.0.113.7');
```

`scopeKey` для метода **без** cache scope бросает
`\InvalidArgumentException` — у такого метода один общий slot, и scope key там
— ошибка; вызывайте `purge(method, params)`. Чтобы дропнуть записи всех
владельцев сразу, используйте `purgeMethod()` или тег.

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

Методы без scope получают читаемый ключ:

```
{key_prefix}.{method}.sha1({stable_sorted_params})
например rpc.cache.weather.get.52adfcb52fb68d9bf6baa00f81684b77e3263ae9   ({"city": "Berlin"})
```

Stable-sorted значит: ассоциативные params сортируются по ключу, list-params
сохраняют порядок. Один и тот же JSON-объект независимо от порядка ключей →
один и тот же хэш.

Если такой ключ длиннее `cache.max_readable_key_length` (по дефолту 200) или
содержит символы вне `[A-Za-z0-9_.-]` (например, кастомный `key_prefix` с
зарезервированными PSR-6 символами `{}()/\@:`), он сворачивается в хэшированную
форму.

Записи методов **со scope** всегда используют хэшированную форму — scope key
это произвольная строка, и по читаемой склейке нельзя отличить метод `a.b` от
метода `a` со scope key `b`:

```
{hash_prefix}.sha1(serialize([{key_prefix}, {method}, {scope key}, sha1({stable_sorted_params})]))
например rpc.108c12fa023dc0cdaa4b02d5b78350e9873130fd   (user.profile, user:alice, {"userId": 42})
```

`key_prefix` по дефолту `rpc.cache`, `hash_prefix` — `rpc`. `purge()`,
`purgeMethod()` и теги адресуют те же ключи, что читают и пишут вызовы.

После обновления до 1.7 меняются все ключи кэша — и читаемые, и
хэшированные, а `UserScope` теперь ключует гостей как `guest` вместо
`user:anon`, — поэтому кэш один раз стартует холодным; старые записи истекают
по TTL.
