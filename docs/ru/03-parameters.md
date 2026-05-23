# 03 — Параметры и DTO

Три способа принимать параметры, выбираются по сигнатуре handler'а:

| Паттерн | Сигнатура | Когда |
|---|---|---|
| **DTO** | `__invoke(MyRequest $req)` | Больше одного поля, особенно если нужна валидация. |
| **`#[Rpc\Param]`** | `__invoke(#[Rpc\Param] int $userId)` | Один-два скаляра, или DTO ощущается избыточным. |
| **`RpcRequest`** | `__invoke(RpcRequest $req)` | Схемы, меняющиеся в рантайме, или прокси, форвардящие как есть. |

Все три можно смешивать с injectable-параметрами (`Context`, `Request`).

## Плоский объект `params` в JSON-запросе

Сколько бы PHP-параметров ни было у handler'а, **клиент всегда шлёт один
JSON-объект** в теле HTTP-запроса — внутри ключа `params` JSON-RPC (без
вложенного «мешка» на имя DTO-параметра):

```json
{
  "params": {
    "email": "x@y",
    "limit": 25,
    "user_id": 42
  }
}
```

Бандл маппит ключи так:

| Форма handler'а | Что в `params` | MCP / OpenRPC `inputSchema` |
|---|---|---|
| **Один DTO** | Ключи = поля конструктора DTO | Те же плоские ключи |
| **DTO + scalar(ы)** | Поля DTO **и** scalar-ключи рядом | Те же плоские ключи |
| **Только scalars** (`#[Rpc\Param]` или auto-promote) | Один ключ на business-параметр | Те же плоские ключи |
| **Несколько DTO** | Объединение ctor-ключей всех DTO (плоско) | Те же плоские ключи — **разрешено**; дубликат JSON-ключа ломает сборку |
| **Только `RpcRequest`** | Что читаете вручную | Пустая / без авто-схемы |

Примеры:

```php
// Один DTO — params: {"email": "...", "limit": 25}
public function __invoke(GetUserRequest $req, Context $ctx): array

// DTO + scalar — params: {"street": "...", "city": "...", "autoId": 7}
public function __invoke(AddressDto $address, #[Assert\Positive] int $autoId, Context $ctx): array

// Только scalars — params: {"user_id": 42, "reason": null}
public function __invoke(#[Rpc\Param('user_id')] int $userId, ?string $reason, Context $ctx): array

// Два DTO — params: {"street": "...", "city": "...", "email": "..."}
// (AddressDto + ContactDto, если имена полей ctor не пересекаются)
public function __invoke(AddressDto $address, ContactDto $contact, Context $ctx): array
```

Auto-promotion: «голый» builtin / `mixed` (не `Context`, не `Request`, не
`RpcRequest`, не class-DTO) ведёт себя как `#[Rpc\Param]` с `name` =
имя PHP-параметра — попадает в MCP/OpenRPC без атрибута.

Владение ключом проверяется при **сборке контейнера** — каждый JSON-ключ в
`params` должен принадлежать ровно одному business-параметру. Коллизия ломает
сборку (поле `city` в двух DTO, или DTO `id` и scalar `$id`). Лимита на число
DTO нет — запрещены только повторяющиеся ключи.

## Паттерн 1 — DTO

```php
final readonly class GetUserRequest
{
    public function __construct(
        #[Assert\Email]
        public string $email,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
    ) {}
}

#[Rpc\Method('user.get')]
final class GetUser
{
    public function __invoke(GetUserRequest $req, Context $ctx): array
    {
        // $req полностью валидирован; $req->email — гарантированно непустая email-строка.
    }
}
```

Dispatcher:

1. Денормализует JSON-объект в DTO через Symfony `DenormalizerInterface`.
2. Валидирует получившийся instance через Symfony Validator.
3. Бросает `InvalidParamsException` (-32602) при любой ошибке, со списком
   per-field violations в `error.data`.

### Отказ от unknown-полей

По дефолту неизвестные ключи дают `Invalid params`:

```json
{"params": {"email": "x@y", "limit": 25, "deprecatedField": 1}}
```

```json
{
  "error": {
    "code": -32602,
    "message": "Unknown parameter(s): deprecatedField. Set ...",
    "data": [{"path": "deprecatedField", "message": "Unknown parameter", "code": null}]
  }
}
```

Ловит опечатки клиентов. Отключите per-method, когда нужна обратная совместимость:

```php
#[Rpc\Method('user.legacy_get', rejectUnknown: false)]
```

Или глобально:

```yaml
json_rpc_server:
  params:
    reject_unknown: false
```

### Позиционные параметры для DTO-метода

По дефолту метод с одним DTO **требует именованных параметров** (`{...}`).
Позиционные (`[...]`) отклоняются — они привязывают порядок аргументов
конструктора DTO к публичному API.

Чтобы разрешить:

```php
#[Rpc\Method('user.get', allowPositionalDto: true)]
```

Или глобально:

```yaml
json_rpc_server:
  params:
    allow_positional_dto: true
```

При разрешении `"params": ["x@y", 25]` маппится позиционно на аргументы
конструктора DTO.

### Вложенный DTO vs массив DTO

Поле DTO может быть другим DTO или списком DTO:

```php
final readonly class TeamRequest
{
    /**
     * @param list<MemberDto> $members
     */
    public function __construct(
        #[Assert\Valid]
        public array $members,
    ) {}
}
```

Что клиент кладёт в `params`:

```json
{"params": {"members": [{"name": "alice"}, {"name": "bob"}]}}
```

- **Runtime (PHP):** Symfony Serializer денормализует элементы в `MemberDto`, если
  в PHPDoc конструктора указано `list<MemberDto>` (тот же doc, что и без
  бандла). Для валидации элементов — `#[Assert\Valid]`.
- **MCP / OpenRPC (документация):** `JsonSchemaBuilder` читает PHPDoc через
  `symfony/property-info` и добавляет ключ JSON Schema **`items`** — это
  **под-схема одного элемента**, а не JSON-массив из запроса клиента.

  Не путать:

  | | Назначение | Форма |
  |---|---|---|
  | **`params.members` в JSON запроса** | То, что реально шлёт Postman / фронт | JSON-**массив** объектов: `[{"name":"alice"}, …]` |
  | **`inputSchema.properties.members.items`** | Правило из [JSON Schema](https://json-schema.org/understanding-json-schema/reference/array) | JSON-**объект** — схема элемента: `{ "type": "object", "properties": { "name": … } }` |

  `items` здесь не `[]` — пустой JSON-массив в `items` означал бы tuple-validation
  (draft-07), бандл так не строит схему. Для `list<MemberDto>` сервер отдаёт,
  например:

  ```json
  "members": {
    "type": "array",
    "items": {
      "type": "object",
      "properties": {
        "name": { "type": "string" }
      },
      "required": ["name"],
      "additionalProperties": false
    }
  }
  ```

  Для `list<string>` и других scalar-элементов в схеме пока только
  `{ "type": "array" }` без `items` — вложенный `items` строится для object-типов.

### Ассоциативные map (`array<string, T>`)

В PHP поле объявлено как `array`, а клиент в JSON шлёт **объект**
`{ "ключ": значение, … }`, а не массив `[…]`. Типичные примеры: `filters`,
`tags`, карты метаданных.

Укажите PHPDoc на promoted-свойстве или параметре конструктора:

```php
final readonly class ListRequest
{
    public function __construct(
        /** @var array<string, FilterSpec> */
        public array $filters = [],
    ) {}
}
```

Пример запроса (фрагмент `params`):

```json
{"params": {"filters": {"groupId": {"mode": "include", "value": [1]}}}}
```

Сгенерированная схема OpenRPC / MCP (`type: object`, не `type: array` + `items`):

```json
"filters": {
  "type": "object",
  "additionalProperties": {
    "type": "object",
    "properties": { "mode": { "type": "string" }, "value": { "type": "array" } }
  }
}
```

`@var` — на **свойстве** (promoted ctor param) или на matching `@param` у
конструктора; docblock только на классе не читается. Union в значении
(`array<string, A|B>`) → `additionalProperties.oneOf`.

## Паттерн 2 — `#[Rpc\Param]`

Для метода с одним-двумя скалярами DTO избыточен. Используйте `#[Rpc\Param]`:

```php
#[Rpc\Method('user.findById')]
final class FindById
{
    public function __invoke(
        #[Rpc\Param('user_id')]                          // переименовать JSON-ключ
        #[Assert\Positive]                                // стандартный валидатор
        int $userId,

        #[Rpc\Param('reason', required: false)]
        ?string $reason = null,

        Context $ctx,
    ): array {
        // $userId провалидирован (positive). $reason может быть null.
    }
}
```

Эффекты:

- `name:` — JSON-ключ, по которому ищется значение
  (`{"user_id": 42}` ↔ `$userId`). По умолчанию совпадает с именем PHP-параметра.
- Validator-атрибуты (`#[Assert\Positive]`, `#[Assert\Email]`, ...) на том же
  параметре проверяются. Violations всплывают как -32602 с именем параметра
  в `path`.
- Параметр попадает в MCP `inputSchema` и OpenRPC документ — даже DTO-less
  методы остаются discoverable.

`required:` — это информация для JSON Schema. Реальная обязательность
определяется PHP-сигнатурой: default value или nullable тип делает параметр
optional.

## Паттерн 3 — `RpcRequest`

Для методов, которым нужно инспектировать сырой envelope (кастомный роутинг,
прокси, обобщённые схемы):

```php
#[Rpc\Method('legacy.proxy')]
final class LegacyProxy
{
    public function __invoke(RpcRequest $req): array
    {
        // $req->id, $req->method, $req->params, $req->isNotification
        $value = $req->params->requireString('targetMethod');
        // ...
    }
}
```

### Аксессоры `RpcParams`

`$req->params` — это `RpcParams`, типизированный доступ к JSON-RPC `params`.
Похож на `InputBag` из Symfony:

| Метод | Возвращает | При отсутствии или null |
|---|---|---|
| `getString($key, $default)` | `string` | возвращает `$default` |
| `getInt($key, $default)` | `int` | возвращает `$default` |
| `getFloat($key, $default)` | `float` | возвращает `$default` |
| `getBool($key, $default)` | `bool` | возвращает `$default` |
| `getArray($key, $default)` | `array` | возвращает `$default` |
| `requireString($key)` | `string` | бросает `InvalidParamsException` |
| `requireInt($key)` | `int` | бросает `InvalidParamsException` |
| `requireFloat($key)` | `float` | бросает `InvalidParamsException` |
| `requireBool($key)` | `bool` | бросает `InvalidParamsException` |
| `requireArray($key)` | `array` | бросает `InvalidParamsException` |

Все типизированные геттеры **строгие**: при wrong-shape value бросают -32602,
не делают silent coercion.

Позиционный доступ: `$req->params->at(0)`, `$req->params->isList()`,
`$req->params->count()`.

## Инжектируемые параметры

Распознаются по типу и резолвятся диспатчером — никогда не приходят из
JSON envelope'а:

| Тип | Что это |
|---|---|
| `Knetesin\JsonRpcServerBundle\Context\Context` | Per-call context: `methodName`, `requestId`, `user`, `roles`. См. [Context](./14-context.md). |
| `Symfony\Component\HttpFoundation\Request` | HTTP-запрос. Берётся из `RequestStack`. Бросает если нет активного запроса (например, в unit-тесте вне контекста). |
| `Knetesin\JsonRpcServerBundle\Request\RpcRequest` | Декодированный JSON-RPC envelope. |

Можно сочетать — `__invoke(MyDto $req, Context $ctx, Request $http)`
работает.

## Даты и date-time

Бандл поставляется с `Knetesin\JsonRpcServerBundle\Type\Date` для "даты без времени" (PHP
такого типа не имеет):

```php
final readonly class CreateEventRequest
{
    public function __construct(
        public Date $startsOn,                  // только дата
        public \DateTimeImmutable $startsAt,    // дата+время
    ) {}
}
```

Input лояльный — бандл принимает ISO, кастомные форматы, "yesterday" или
unix-timestamp'ы в зависимости от конфигурации:

```yaml
json_rpc_server:
  serializer:
    datetime_format: 'iso8601'   # или 'timestamp' / 'timestamp_ms' / custom php date()
    date_format: 'Y-m-d'
    timezone: 'UTC'
```

Например, при `datetime_format: timestamp_ms`:

- Output: `DateTimeImmutable` → integer (Unix ms)
- Input: любое из `1773483072345`, `"2026-03-14T10:11:12+00:00"`, `"yesterday"`
- MCP/OpenRPC схемы корректно отдают `{type: 'integer'}`

Детали в [Configuration reference](./13-configuration.md#serializer).
