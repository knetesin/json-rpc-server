# 13 — Configuration reference

Каждый YAML-ключ, с дефолтами и рекомендациями.

## Дефолты верхнего уровня

```yaml
json_rpc_server: ~
```

Эквивалентно:

```yaml
json_rpc_server:
  max_request_size: 1048576       # 1 MiB
  max_json_depth: 32

  security:
    roles_match: any
    expose_role_names: true
    default_roles: []         # напр. ['ROLE_USER'] для secure-by-default
    public_prefixes: []       # напр. ['public.', 'health.']
    public_methods: []        # напр. ['ping', 'version']
    prefix_roles: {}          # напр. {'admin.': ['ROLE_ADMIN'], 'internal.': ['ROLE_INTERNAL']}

  params:
    allow_positional_dto: false
    reject_unknown: true

  serializer:
    datetime_format: iso8601
    date_format: 'Y-m-d'
    timezone: null

  routes:
    rpc: /rpc
    stream: /rpc/stream
    mcp_tools: /mcp/tools
    mcp_call: /mcp/call

  cache:
    default_pool: cache.app
    pools: {}

  profiler:
    enabled: true

  mcp:
    enabled: true
    default_format: json
    apply_rate_limit: false
    expose_all: false
    exclude_prefixes: []
    exclude_methods: []
    whitelist_methods: []
```

## `max_request_size`

Лимит размера тела в байтах. 0 выключает проверку глобально (но per-method
`#[Rpc\MaxRequestSize]` всё равно применяется).

```yaml
json_rpc_server:
  max_request_size: 5242880   # 5 MiB
```

Oversized payload'ы возвращают HTTP 413 с JSON-RPC error envelope.

Когда глобальный лимит > 0, parser cap бандла поднимается на этапе сборки
контейнера до максимального per-method значения — чтобы метод с более высоким
лимитом не отсекался парсером до dispatcher'а. Когда глобальный лимит = 0
(uncapped), parser cap тоже остаётся 0 независимо от per-method значений:
иначе один метод с маленьким cap'ом тихо обрезал бы все остальные методы
ещё на парсере.

## `max_json_depth`

Максимальная глубина вложенности для `json_decode` входящих RPC / MCP
payload'ов. Поднимайте только если клиенты шлют легитимно глубокие структуры —
большая глубина увеличивает parser stack.

```yaml
json_rpc_server:
  max_json_depth: 64
```

## `http_status.enabled`

По умолчанию `false`. При `true` `/rpc` мапит `error.code` в HTTP-статусы
(400/404/429/500) как `/rpc/stream`. Oversized body всё равно **413**, даже
когда флаг выключен. В batch — максимальный статус среди элементов.

```yaml
json_rpc_server:
  http_status:
    enabled: true
```

## `security.roles_match`

Дефолт когда `#[Rpc\Method(rolesMatch: ...)]` не указан.

- `any` — хотя бы одна роль (default, OR)
- `all` — все роли (AND)

```yaml
json_rpc_server:
  security:
    roles_match: all   # строже дефолт
```

## `security.expose_role_names`

`true` (dev-friendly дефолт) — `AccessDenied` сообщения называют недостающие
роли. `false` — сообщение схлопывается в `Access denied`. Для production
deployment'ов где role-ID несут бизнес-структуру.

```yaml
# config/packages/prod/rpc.yaml
json_rpc_server:
  security:
    expose_role_names: false
```

## `security.default_roles` / `public_prefixes` / `public_methods` / `prefix_roles`

По умолчанию `[]` / `[]` / `[]` / `{}` — историческое поведение "нет `roles:`
в атрибуте = публичный метод". Задайте `default_roles`, чтобы переключить
бандл в режим secure-by-default: каждый метод без своего
`#[Rpc\Method(roles: [...])]` получает роли из конфига, а `public_prefixes`
и `public_methods` — allowlist для эндпоинтов, которые должны остаться
анонимными.

`prefix_roles` позволяет задать роли по умолчанию точечно — для префикса имён
методов, без правки каждого хендлера: напр., `admin.*` → `ROLE_ADMIN`,
`internal.*` → `ROLE_INTERNAL`, всё остальное по-прежнему откатывается на
`default_roles`. При перекрытии побеждает самый длинный префикс (`admin.users.`
перекрывает `admin.` для `admin.users.create`).

Порядок резолюции (первое совпадение выигрывает, считается на compile time):

1. атрибут содержит непустые `roles` → используется как есть
2. имя метода в `public_methods` → публичный
3. имя начинается с одного из `public_prefixes` → публичный
4. имя совпадает с `prefix_roles` (длиннейший префикс выигрывает) → эти роли
5. `default_roles` не пуст → подставляются дефолтные
6. иначе → публичный

```yaml
json_rpc_server:
  security:
    default_roles: ['ROLE_USER']
    public_prefixes: ['public.', 'health.']
    public_methods: ['ping']
    prefix_roles:
      'admin.':    ['ROLE_ADMIN']
      'internal.': ['ROLE_INTERNAL']
```

Резолвленные роли попадают в `MethodMetadata::$roles` — `debug:rpc` и
профайлер показывают итоговое значение, а не исходник.

## `params.allow_positional_dto`

Принимают ли handler'ы с одним DTO позиционные JSON-RPC params (`"params":
[...]`). Запрещено по дефолту — позиционные параметры связывают порядок
аргументов конструктора DTO с публичным API.

```yaml
json_rpc_server:
  params:
    allow_positional_dto: true
```

Per-method override через `#[Rpc\Method(allowPositionalDto: true)]`.

## `params.reject_unknown`

Отклоняет ли DTO denormalization unknown-поля. По дефолту `true` ловит опечатки
клиентов и старые stale-ключи. Поставьте `false` для backward-совместимых
endpoint'ов, которые должны silently принимать лишние ключи.

```yaml
json_rpc_server:
  params:
    reject_unknown: false
```

Per-method override через `#[Rpc\Method(rejectUnknown: false)]`.

## `serializer`

Date/time форматирование `DateNormalizer`'а. **Output строгий** (использует
конфигурированные форматы дословно). **Input лояльный** — детали ниже.

### `serializer.datetime_format`

Output формат для `DateTimeInterface`. Один из:

- `iso8601` (default) — `Y-m-d\TH:i:sP`, например `2026-05-21T15:00:00+03:00`
- `timestamp` — Unix seconds, **integer**
- `timestamp_ms` — Unix milliseconds, **integer**
- любой raw php `date()` формат — например `'Y-m-d H:i:s'`

На input, числа интерпретируются как:

- секунды если `datetime_format = timestamp`
- миллисекунды если `datetime_format = timestamp_ms`
- секунды иначе

Строки идут через `new \DateTimeImmutable($s)` — принимает ISO, RFC,
"yesterday", "2024-01-01 12:00" и т.д.

```yaml
json_rpc_server:
  serializer:
    datetime_format: timestamp_ms
    timezone: UTC
```

JSON Schema (`/mcp/tools` и OpenRPC) автоматически отдаёт корректный wire-тип:
`{type: "integer"}` для timestamp форматов, `{type: "string", format:
"date-time"}` для остальных.

### `serializer.date_format`

Output формат для `Type\Date` (дата без времени). Default `Y-m-d`.

На input:

- строки: сначала пробуется конфигурированный формат строго, потом fallback
  на `new \DateTimeImmutable` (так что `"21.05.2026"`, `"2026/05/21"`,
  `"yesterday"` парсятся)
- числа: timestamp по `datetime_format`, обрезается до даты в
  конфигурированной `timezone` (или UTC)

```yaml
json_rpc_server:
  serializer:
    date_format: 'd.m.Y'
```

### `serializer.timezone`

Timezone, применяется при нормализации `DateTimeInterface` в строку и при
обрезании timestamp'ов до даты. UTC настоятельно рекомендуется для
cross-TZ корректности. `null` — оставить timezone источника как есть.

```yaml
json_rpc_server:
  serializer:
    timezone: 'UTC'
```

## `routes`

Переопределение URL каждого транспорта. Полезно за прокси, который снимает
префиксы.

```yaml
json_rpc_server:
  routes:
    rpc: '/api/rpc'
    stream: '/api/rpc/stream'
    mcp_tools: '/api/mcp/tools'
    mcp_call: '/api/mcp/call'
```

## `cache.default_pool`

ID сервиса PSR-6 пула, используемый когда `#[Rpc\Cache]` не указывает `pool:`.

```yaml
json_rpc_server:
  cache:
    default_pool: 'app.short_lived'
```

## `cache.pools`

Именованная карта дополнительных пулов, на которые может ссылаться
`#[Rpc\Cache(pool: "name")]`.

```yaml
framework:
    cache:
        pools:
            app.long_lived:
                adapter: cache.adapter.redis
                tags: true

json_rpc_server:
  cache:
    default_pool: 'cache.app'
    pools:
      long_lived: app.long_lived    # alias → service id
      sessions: app.session_cache
```

Тогда `#[Rpc\Cache(pool: 'long_lived')]` резолвится в `app.long_lived`.

## `profiler.enabled`

Записывать RPC-вызовы в toolbar и панель Web Profiler. Активно только когда
`kernel.debug = true`; в проде subscriber — no-op.

```yaml
json_rpc_server:
  profiler:
    enabled: false   # выключить даже в dev
```

## `mcp.enabled`

Отключите чтобы вовсе не регистрировать MCP-сервисы и роуты.

```yaml
json_rpc_server:
  mcp:
    enabled: false
```

`JsonSchemaBuilder` остаётся доступен — `debug:rpc --openrpc` всё ещё работает.

## `mcp.default_format`

Формат результата, когда ни `X-Mcp-Format` header, ни `?format=` query
parameter, ни `#[Rpc\Mcp(format: ...)]` ничего не указали.

```yaml
json_rpc_server:
  mcp:
    default_format: toon   # 30-50% меньше LLM-токенов на list-payload'ах
```

## `mcp.apply_rate_limit`

Применяется ли `#[Rpc\RateLimit]` на `/mcp/call`. Default `false` — MCP-трафик
обычно от доверенного внутреннего агента. Включите для публичного MCP.

```yaml
json_rpc_server:
  mcp:
    apply_rate_limit: true
```

## `mcp.expose_all`

`true` — все методы выставлены через MCP кроме отфильтрованных.
`false` (default) — выставлены только методы с `#[Rpc\Mcp]`.

```yaml
json_rpc_server:
  mcp:
    expose_all: true
    exclude_prefixes: ['internal.', 'debug.']
```

## `mcp.exclude_methods` / `mcp.whitelist_methods` / `mcp.exclude_prefixes`

Operator-level фильтры. Приоритет — см. [MCP](./08-mcp.md#opt-in).

```yaml
json_rpc_server:
  mcp:
    exclude_methods: ['user.delete', 'admin.purge']
    whitelist_methods: ['user.get', 'user.list', 'search.public']
    exclude_prefixes: ['internal.', 'debug.']
```

## Per-environment конфиг

Стандартный Symfony config inheritance. Например, dev с именами ролей,
prod без:

```yaml
# config/packages/json_rpc_server.yaml — общие дефолты
json_rpc_server:
  max_request_size: 1048576
  security:
    expose_role_names: true
  mcp:
    apply_rate_limit: false

# config/packages/prod/rpc.yaml — prod overrides
json_rpc_server:
  security:
    expose_role_names: false
  mcp:
    apply_rate_limit: true
```

## Параметры из PHP

Если нужно читать resolved config в runtime'е, есть параметры контейнера:

| Параметр | Источник |
|---|---|
| `%json_rpc_server.max_request_size%` | `max_request_size` |
| `%json_rpc_server.max_json_depth%` | `max_json_depth` |
| `%json_rpc_server.http_status.enabled%` | `http_status.enabled` |
| `%json_rpc_server.security.roles_match%` | `security.roles_match` |
| `%json_rpc_server.security.expose_role_names%` | `security.expose_role_names` |
| `%json_rpc_server.params.allow_positional_dto%` | `params.allow_positional_dto` |
| `%json_rpc_server.params.reject_unknown%` | `params.reject_unknown` |
| `%json_rpc_server.serializer.datetime_format%` | `serializer.datetime_format` |
| `%json_rpc_server.serializer.date_format%` | `serializer.date_format` |
| `%json_rpc_server.serializer.timezone%` | `serializer.timezone` |
| `%json_rpc_server.cache.default_pool%` | `cache.default_pool` |
| `%json_rpc_server.routes.{name}%` | `routes.{name}` |
| `%json_rpc_server.mcp.enabled%` | `mcp.enabled` |
| `%json_rpc_server.mcp.default_format%` | `mcp.default_format` |
| `%json_rpc_server.mcp.apply_rate_limit%` | `mcp.apply_rate_limit` |
| `%json_rpc_server.mcp.expose_all%` | `mcp.expose_all` |
| `%json_rpc_server.mcp.exclude_*%`, `%json_rpc_server.mcp.whitelist_methods%` | соответствующие конфиги |
