# 08 — MCP

[Model Context Protocol](https://modelcontextprotocol.io/) — стандарт Anthropic
для выставления tools и ресурсов LLM-клиентам (Claude Desktop, ваши собственные
LLM-агенты, etc.). Бандл выставляет любой RPC-метод как MCP tool без дубляжа.

## Два endpoint'а

| Path | Метод | Возвращает |
|---|---|---|
| `/mcp/tools` | GET | `{"tools": [{name, description, roles, inputSchema}]}` |
| `/mcp/call` | POST | `{"content": [...], "structuredContent": ...}` |

Body `/mcp/call`:

```json
{ "name": "user.get", "arguments": { "email": "x@y" } }
```

## Opt-in

По дефолту экспонируются только методы с `#[Rpc\Mcp]`:

```php
#[Rpc\Method('user.get')]
#[Rpc\Mcp(description: 'Найти пользователя по email.')]
final class GetUser { /* … */ }
```

Чтобы выставить всё, кроме нескольких:

```yaml
json_rpc_server:
  mcp:
    expose_all: true
    exclude_prefixes: ['internal.', 'debug.']
    exclude_methods: ['user.delete']
```

Запретить всё, кроме нескольких:

```yaml
json_rpc_server:
  mcp:
    whitelist_methods: ['user.get', 'user.list']
```

Приоритет фильтра (first match wins):

1. `exclude_methods` — явный deny
2. `whitelist_methods` — явный allow
3. `#[Rpc\Mcp(enabled: false)]` — opt-out разработчика
4. Метод deprecated (и нет явного `#[Rpc\Mcp]`) → скрыт
5. `exclude_prefixes` — bulk deny
6. `expose_all: true` → exposed
7. `#[Rpc\Mcp]` присутствует → exposed
8. Иначе → скрыт

Operator config (`exclude_*`, `whitelist_*`) бьёт атрибут разработчика — у
владельца деплоя последнее слово.

## Полное выключение MCP

```yaml
json_rpc_server:
  mcp:
    enabled: false
```

Снимает routes и services. `JsonSchemaBuilder` остаётся доступен, чтобы
`debug:rpc --openrpc` работал.

## Input schema

Бандл precompute-ит JSON Schema draft-07 фрагмент для input'а каждого метода на
сборке контейнера. `/mcp/tools` отдаёт их напрямую — никакой reflection
на каждый запрос.

Покрытие:

| Источник | JSON Schema |
|---|---|
| `string`, `int`, `float`, `bool`, `array` | `{type: "..."}` |
| `?T` | `{type: ["T", "null"]}` |
| Backed enum | `{type, enum: [...]}` |
| Обычный enum | `{type: "string", enum: [...]}` |
| `\DateTimeInterface` | зависит от `datetime_format` — `string/date-time` или `integer` |
| `Type\Date` | `{type: "string", format: "date"}` |
| `#[Assert\Length(min, max)]` | `minLength`, `maxLength` |
| `#[Assert\Range(min, max)]` | `minimum`, `maximum` |
| `#[Assert\Positive]` | `exclusiveMinimum: 0` |
| `#[Assert\Email]` | `format: email` |
| `#[Assert\Url]` | `format: uri` |
| `#[Assert\Regex]` | `pattern: ...` |
| `#[Assert\Choice]` | `enum: [...]` |

Незнакомые констрейнты пропускаются (не угадываются).

## Форматы результата

Как `__invoke` output рендерится в MCP `content`. Дефолт — компактный JSON;
выбирайте по нужде LLM:

```php
#[Rpc\Mcp(format: McpFormat::Toon)]
```

| Формат | Wire | Когда |
|---|---|---|
| `json` (default) | compact JSON | Большинство кейсов. |
| `pretty_json` | JSON с отступами | Дебаг через Claude Desktop. |
| `markdown` | Markdown table если однородные ряды; JSON иначе | Human-readable summaries. |
| `plain` | Строковое представление scalar'ов; JSON для структур | One-line scalar results. |
| **`toon`** | TOON (token-efficient) | LLM list payloads — 30–50% меньше токенов. |

Плюс `structuredContent` (нормализованная объектная форма) всегда добавляется
рядом с `content` для non-scalar результатов — MCP spec рекомендует это, чтобы
machine-parsing клиентам не приходилось re-парсить текстовый блок.

### Приоритет резолва формата

1. `X-Mcp-Format: toon` header запроса
2. `?format=toon` query parameter
3. `#[Rpc\Mcp(format: McpFormat::Toon)]` атрибут
4. `json_rpc_server.mcp.default_format` конфиг бандла
5. Дефолт: `json`

## Кастомизация результата: McpResultTransformer

Когда JSON-RPC response содержит поля, которые не должна видеть LLM (внутренние
IDs, debug-флаги, cache-ключи), реализуйте `McpResultTransformer` на handler'е:

```php
use JsonRpcServer\Mcp\McpResultTransformer;

#[Rpc\Method('user.getById')]
#[Rpc\Mcp]
final class GetById implements McpResultTransformer
{
    public function __invoke(GetByIdRequest $req): UserResponse { /* ... */ }

    public function transformMcpResult(mixed $result): mixed
    {
        // $result уже нормализован (array form).
        unset($result['internalDebugFlags'], $result['cacheKey']);
        return $result;
    }
}
```

Запускается после `__invoke` и после нормализации. JSON-RPC `/rpc` ответ не
затрагивается — только `/mcp/call` видит трансформированный output.

Для bulk-перешейпинга нескольких методов лучше кастомный `McpResultFormatter`
(декоратор `DefaultMcpResultFormatter`).

## Description

```php
#[Rpc\Mcp(description: 'Получить профиль юзера по email. Возвращает id, email, name.')]
```

Откатывается на `#[Rpc\Method(description: ...)]` если опущен.

## Rate limiting для MCP

`#[Rpc\RateLimit]` **не** применяется к `/mcp/call` по дефолту — MCP-трафик
обычно от доверенного внутреннего агента. Включите для публичного MCP:

```yaml
json_rpc_server:
  mcp:
    apply_rate_limit: true
```

## HTTP-статусы

| Тип | Статус | Body |
|---|---|---|
| Parse / невалидный envelope | 400 | `{isError: true, error: {...}, content: [text]}` |
| Method not found / не exposed | 404 | то же |
| Body too large | 413 | то же |
| Auth, rate limit, invalid params, internal error | 200 | то же |

200 для handler-level ошибок — MCP-конвенция. Клиенты проверяют `isError: true`
в body, не HTTP-статус.

## Подключение Claude Desktop

```json
{
  "mcpServers": {
    "myapp": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch", "https://api.example.com/mcp"]
    }
  }
}
```

Или любой MCP HTTP-транспорт, который вызывает `/mcp/tools` и `/mcp/call`.

## Формат TOON — когда выигрывает

TOON кодирует списки однородных плоских объектов как табличную форму:

```
users[3]{id,name,email}:
  1,Alice,alice@example.com
  2,Bob,bob@example.com
  3,Carol,carol@example.com
```

vs JSON:

```json
[{"id":1,"name":"Alice","email":"alice@example.com"},
 {"id":2,"name":"Bob","email":"bob@example.com"},
 {"id":3,"name":"Carol","email":"carol@example.com"}]
```

Для 100 рядов × 6 колонок JSON-версия ~2× токенов. Дефолт остаётся JSON,
потому что большинство LLM ровнее round-trip'ят JSON; переключайтесь на `toon`
для read-heavy listing-методов осознанно.
