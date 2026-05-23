# 09 — OpenRPC

[OpenRPC](https://spec.open-rpc.org/) — для JSON-RPC то, чем OpenAPI для REST:
machine-readable контракт, который потребляют SDK-генераторы, doc-renderer'ы и
mocking-серверы.

Бандл эмитит валидный OpenRPC 1.3.2 документ для всех зарегистрированных
методов:

```bash
bin/console debug:rpc --openrpc \
    --title="Billing API" \
    --api-version="2.4.0" \
    > openrpc.json
```

## Что эмитится

```json
{
  "openrpc": "1.3.2",
  "info": {
    "title": "Billing API",
    "version": "2.4.0"
  },
  "methods": [
    {
      "name": "user.get",
      "description": "Найти пользователя по email.",
      "params": [
        {
          "name": "email",
          "required": true,
          "schema": {
            "type": "string",
            "format": "email"
          }
        },
        {
          "name": "limit",
          "required": false,
          "schema": {
            "type": "integer",
            "minimum": 1,
            "maximum": 100
          }
        }
      ],
      "result": {
        "name": "user.get_result",
        "schema": {
          "type": "object",
          "properties": { ... },
          "additionalProperties": false
        }
      },
      "x-rpc-roles": ["ROLE_USER"],
      "x-rpc-roles-match": "any"
    },
    {
      "name": "user.legacy_get",
      "deprecated": true,
      "x-deprecation-reason": "Use user.get instead.",
      ...
    }
  ]
}
```

## Flattening параметров

OpenRPC `params` строятся из той же прекомпилированной JSON Schema, что и MCP
`inputSchema` (на этапе сборки контейнера). Каждый **business**-ключ плоской
схемы становится отдельным OpenRPC-параметром.

Покрывает:

- **Один DTO** — поля конструктора (`email`, `limit`, …).
- **DTO + scalar рядом** — поля DTO и `#[Rpc\Param]` / auto-promoted scalars
  (`street`, `city`, `autoId`).
- **Только scalars** — один entry на параметр.

Server-side (`Context`, `Request`, `RpcRequest`) не попадают в контракт.

Два DTO с общим top-level JSON-ключом отклоняются при сборке контейнера (как
и при runtime resolution).

## Кастомные `x-` расширения

Бандл эмитит некоторые non-standard поля в `x-` namespace. OpenRPC клиенты
обязаны игнорировать unknown `x-` ключи; bundle-aware тулинг (ваш собственный
SDK-генератор, docs renderer) может их читать:

| Поле | Тип | Источник |
|---|---|---|
| `x-deprecation-reason` | string | `#[Rpc\Method(deprecated: '...')]` |
| `x-rpc-roles` | string[] | `#[Rpc\Method(roles: [...])]` |
| `x-rpc-roles-match` | `"any"` \| `"all"` | `#[Rpc\Method(rolesMatch: ...)]` |
| `x-rpc-streaming` | boolean | `#[Rpc\Stream]` |
| `x-rpc-stream-format` | string | `#[Rpc\Stream(format: ...)]` |

## Интеграция с SDK-генераторами

### TypeScript

[`@open-rpc/generator`](https://github.com/open-rpc/generator) эмитит типизированные
client SDK:

```bash
npx @open-rpc/generator generate \
    -t client-typescript \
    --openrpcDocument=openrpc.json \
    -o ./sdk-ts
```

Результат: типизированный TS-клиент, где `client.user.get({ email })` возвращает
типизированный результат.

### Python

```bash
npx @open-rpc/generator generate \
    -t client-python \
    --openrpcDocument=openrpc.json \
    -o ./sdk-py
```

### Документация

[`@open-rpc/docs-react`](https://github.com/open-rpc/docs-react) или
[OpenRPC playground](https://playground.open-rpc.org/) рендерят документ в
browsable docs.

## CI интеграция

Генерируйте документ в CI, чтобы ловить непреднамеренные изменения формы API:

```yaml
# .github/workflows/openrpc.yml
- run: bin/console debug:rpc --openrpc > current-openrpc.json
- run: diff openrpc.json current-openrpc.json   # fail at drift
```

Или коммитьте под `docs/openrpc.json` и требуйте чтобы оставался в синке.

## Стратегии версионирования

OpenRPC имеет `info.version` для всего документа. Позиция бандла: имя
JSON-RPC метода — это string namespace; версионируйте через имя.

### Стратегия A: префикс на версию

```php
#[Rpc\Method('v1.user.get')]
#[Rpc\Method('v2.user.get')]
```

Генерируйте один OpenRPC документ покрывающий все версии, или фильтруйте и
эмитьте per-version (нужен кастомный код поверх `OpenRpcDocumentBuilder`).

### Стратегия B: отдельные routes

```yaml
json_rpc_server:
  routes:
    rpc: '/rpc/v2'  # плюс второй экземпляр бандла для /rpc/v1
```

Тяжело — обычно overkill.

### Стратегия C: только `info.version`

Штампуйте бандл как v2.4.0, deprecate'те методы по отдельности:

```php
#[Rpc\Method('user.legacy_get', deprecated: 'Use user.get instead.')]
```

Рекомендуемый дефолт.

## Ограничения

Schema builder покрывает PHP-типы + curated набор Validator-констрейнтов.
Что не моделируется:

- **Массив DTO** — для `array` + PHPDoc `list<YourDto>`, `Foo[]` или
  `array<int, YourDto>` в схеме есть вложенный `items` для `YourDto`. Нужен
  тот же PHPDoc, что для Serializer (`phpstan/phpdoc-parser` и
  `phpdocumentor/type-resolver` — зависимости бандла). Без PHPDoc — только
  `{type: 'array'}`.
- **Массив scalars** (`list<int>`, `string[]`, …) — пока `{type: 'array'}`
  без `items`.
- Кастомные Validator-констрейнты — мапятся только стандартные из
  `Symfony\Component\Validator\Constraints\*`. Кастомные проходят валидацию,
  но в schema не отображаются.
- Наследование / интерфейсы в return types — эмитятся как `{type: 'object'}`.

Для полного контроля над schema (например, добавить кастомные `examples` или
`description` к отдельным свойствам) — генерируйте документ, постпроцессите
`jq` / скриптом, и коммитьте отредактированную версию.
