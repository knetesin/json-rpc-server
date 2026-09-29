# 10 — Ошибки

## JSON-RPC error envelope

Стандартная форма 2.0, всегда:

```json
{
  "jsonrpc": "2.0",
  "error": { "code": -32602, "message": "Invalid params", "data": [...] },
  "id": 1
}
```

`data` опциональна и зависит от класса исключения. Ошибки валидации несут
список violations; ошибки rate-limit'а несут `retryAfter`; и т.д.

## Стандартные коды

По JSON-RPC 2.0 §5.1:

| Код | Что | Класс в бандле |
|---|---|---|
| -32700 | Parse error | `ParseException` |
| -32600 | Invalid request | `InvalidRequestException`, `RequestTooLargeException` |
| -32601 | Method not found | `MethodNotFoundException` |
| -32602 | Invalid params | `InvalidParamsException` |
| -32603 | Internal error | `InternalErrorException` |
| -32099 … -32000 | Server-defined range | резервируйте свой |

## Коды бандла

В server-defined диапазоне:

| Код | Что | Класс |
|---|---|---|
| -32001 | Access denied | `AccessDeniedException` |
| -32002 | Not found (entity-level) | `NotFoundException` |
| -32003 | Rate limit exceeded | `RateLimitExceededException` |

Конструкторы принимают override, если ваш контракт использует другие коды:

```php
throw new AccessDeniedException('No access to billing', rpcCode: -33001);
```

## Свой класс исключения

Наследуйте `RpcException`:

```php
use Knetesin\JsonRpcServerBundle\Exception\RpcException;

final class PaymentDeclinedException extends RpcException
{
    public function __construct(
        string $message,
        private readonly string $bankCode,
    ) {
        parent::__construct($message);
    }

    public function rpcCode(): int { return -32010; }

    public function rpcData(): mixed
    {
        return ['bankCode' => $this->bankCode];
    }
}
```

В handler'е:

```php
throw new PaymentDeclinedException('Card declined', bankCode: 'INSUFFICIENT_FUNDS');
```

Wire-форма:

```json
{
  "error": {
    "code": -32010,
    "message": "Card declined",
    "data": {"bankCode": "INSUFFICIENT_FUNDS"}
  }
}
```

## Ошибки валидации

`InvalidParamsException` (-32602) несёт список violations в `data`:

```json
{
  "error": {
    "code": -32602,
    "message": "Invalid params",
    "data": [
      {"path": "email", "message": "This value is not a valid email address.", "code": "bd79c0ab-..."},
      {"path": "age", "message": "This value should be between 0 and 150.", "code": "..."}
    ]
  }
}
```

Каждое entry: `{path, message, code}`. `code` — Symfony validator constraint
UUID; полезно для i18n.

Источники violations:

- Ошибки DTO-денормализации (несовпадение типа, отсутствие required, и т.д.)
- Symfony Validator констрейнты на DTO
- Validator констрейнты на `#[Rpc\Param]` скалярах

MCP endpoint дополнительно рендерит их в `content[0].text`:

```
Error -32602: Invalid params
  - email: This value is not a valid email address.
  - age: This value should be between 0 and 150.
```

Даже text-only LLM-клиенты видят что не так.

## Rate-limit ошибки

`RateLimitExceededException` (-32003) несёт `retryAfter`:

```json
{
  "error": {
    "code": -32003,
    "message": "Rate limit exceeded for billing.heavy",
    "data": {"retryAfter": 42}
  }
}
```

HTTP-ответ также содержит `Retry-After: 42` — HTTP-клиенты могут бэкоффить
без парсинга body.

## HTTP-статусы

JSON-RPC 2.0 идейно HTTP-status-agnostic — каждый ответ мог бы быть 200 с
ошибкой в body. Бандл прагматичнее:

| Тип | `/rpc` (default) | `/rpc` + `http_status.enabled` | `/rpc/stream` (pre-stream) | `/mcp/call` |
|---|---|---|---|---|
| Parse | 200 | 400 | 400 | 400 |
| Invalid request | 200 | 400 | 400 | 400 |
| Method not found | 200 | 404 | 404 | 404 |
| Invalid params | 200 | 400 | 400 | 200 (MCP convention) |
| Access denied (-32001) | 200 | 403 | 403 | 200 (MCP convention) |
| Not found (-32002) | 200 | 404 | 404 | 200 (MCP convention) |
| Rate limit | 200 | 429 | 429 | 200 (MCP convention) |
| Internal error | 200 | 500 | 500 | 200 (MCP convention) |
| Request too large | **413** | **413** | **413** | **413** |

Прочие коды из server-defined диапазона (-32099 … -32000) дают 400, любой
другой код — 500. `/rpc/stream` маппит pre-stream ошибки так всегда, независимо
от `http_status.enabled`, и добавляет `Retry-After` к отказу по rate limit.

На `/rpc` **413** означает, что ничего не выполнялось, и возвращается даже при
`http_status.enabled: false`: либо body больше parser cap (максимум из
`max_request_size` и всех `#[Rpc\MaxRequestSize]`), либо одиночный (не batch)
запрос превышает лимит своего метода. Мониторинг и балансировщики могут
отсекать такой трафик без разбора JSON. Распарсенный batch никогда не получает
413 — см. ниже.

Для остальных ошибок каноничный сигнал — `error.code` в body. Опциональный
HTTP-mapping удобен в dev (браузер, `curl -f`, прокси), но по умолчанию выключен:

```yaml
json_rpc_server:
  http_status:
    enabled: true
```

В batch берётся **максимальный** HTTP-статус среди элементов (например 404 + 200
→ 404). Успешные элементы по-прежнему содержат `result` в body.

## Ошибки отдельных элементов batch

Элемент batch'а, который нельзя выполнить, получает собственную запись с
ошибкой; остальные элементы выполняются, а HTTP-статус считается по правилам
выше (по умолчанию 200):

- **Невалидный элемент** (не объект, нет `jsonrpc: "2.0"` или `method`, неверный
  тип `id`/`params`) → `{"jsonrpc":"2.0","error":{"code":-32600,...},"id":null}`.
  Такой элемент получает запись всегда: распознать в нём notification нельзя.
  `[1,2,3]` даёт три ошибки -32600.
- **Элемент больше `MaxRequestSize` своего метода** → -32600 с `id` элемента.
  Меряется размер самого элемента (его заново закодированный JSON envelope), а
  не всего body batch'а; весь body по-прежнему ограничен parser cap.
- **Streaming-метод** (`#[Rpc\Stream]`) → -32600 с указанием на streaming
  endpoint.

Отклонённые notifications (валидная форма, без `id`) записи не получают.
Отклонённые элементы не доходят ни до handler'а, ни до parallel batch
executor'а. `BatchDispatchedEvent::$batchSize` считает все записи массива
запроса, включая отклонённые.

Проблема уровня всего payload'а по-прежнему даёт один объект ошибки: невалидный
JSON (-32700), пустой batch `[]` или невалидный не-batch запрос (-32600).

## Internal-ошибки

Любой uncaught `\Throwable` из handler'а становится `InternalErrorException`
(-32603) на проводе. Оригинальное исключение логируется через PSR-3 (level
`error`) с полным stack trace до сборки envelope'а. Клиенты видят только
`"Internal error"`, никогда сообщение оригинального исключения — защищает от
случайной утечки info (DB connection strings, etc.).

Если хотите другую границу утечки, бросайте свой `RpcException`-подкласс явно:

```php
try {
    $this->somethingDelicate->run();
} catch (DatabaseException $e) {
    throw new InternalErrorException('Service temporarily unavailable', previous: $e);
}
```

## Notifications и ошибки

JSON-RPC 2.0 говорит, что notifications не дают ответа, даже на ошибке.
Бандл это уважает — исключения всё равно идут как логи и `Failed` события,
но envelope клиенту не доходит. HTTP-ответ — `204 No Content`.
