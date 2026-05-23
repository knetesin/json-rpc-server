# 07 — Стриминг

Бандл выставляет streaming endpoint на `/rpc/stream`, который зеркалит
JSON-RPC envelope на входе и эмитит ряды во времени. Это **не** JSON-RPC 2.0
(спека request/response), а намеренное расширение — для streaming LLM-токенов,
server-sent updates, progressive lists и т.д.

## Включение

Streaming-маршрут **выключен по умолчанию** — поставьте `true`, когда
реально появился `#[Rpc\Stream]`-хендлер:

```yaml
json_rpc_server:
  routes:
    stream: { enabled: true }
```

Если забудете — compiler pass на этапе сборки контейнера бросит понятный
`LogicException` со списком всех методов, у которых стоит атрибут. Никаких
тихих 404.

## Объявление streaming-метода

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;

#[Rpc\Method('chat.stream')]
#[Rpc\Stream(format: StreamFormat::Sse)]
final class ChatStream
{
    /** @return \Generator<int, array<string, mixed>, void, void> */
    public function __invoke(ChatRequest $req): \Generator
    {
        foreach ($this->generate($req) as $chunk) {
            yield ['delta' => $chunk];
        }
    }
}
```

Тип возврата — `iterable<mixed>` (Generator работает лучше всего — отдаёт
по одному ряду, не держа всё в памяти).

## Форматы

| Формат | Content-Type | Wire-формат |
|---|---|---|
| `StreamFormat::Ndjson` (default) | `application/x-ndjson` | Один JSON-объект на строку. |
| `StreamFormat::Sse` | `text/event-stream` | Framing `data: <json>\n\n`. Браузерный `EventSource` работает. |
| `StreamFormat::JsonArray` | `application/json` | `[<json>,<json>,...]` — один валидный JSON document, прогрессивно записанный. |

```php
#[Rpc\Stream(format: StreamFormat::Ndjson)]
#[Rpc\Stream(format: StreamFormat::Sse)]
#[Rpc\Stream(format: StreamFormat::JsonArray)]
```

## Вызов

```bash
curl -X POST http://localhost/rpc/stream \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"chat.stream","params":{"prompt":"…"},"id":1}'
```

Response-заголовки всегда содержат:
- `Cache-Control: no-cache`
- `X-Accel-Buffering: no` (просит nginx/Cloudflare не буферизовать)

Бандл также вызывает `ob_flush()` + `flush()` после каждого ряда — поток
реально стримит под PHP-FPM с дефолтным `output_buffering = 4096`.

## Обработка ошибок

Endpoint различает pre-stream и mid-stream ошибки:

### Pre-stream ошибки

Обнаружены до старта итератора (parse, method-not-found, batch > 1,
method-not-streaming). Результат: обычный JSON-RPC envelope, HTTP 4xx/5xx.

| Тип | Статус |
|---|---|
| Parse / Invalid Request | 400 |
| Method not found | 404 |
| Internal error | 500 |

```json
{"jsonrpc":"2.0","error":{"code":-32600,"message":"Streaming endpoint accepts only a single request"},"id":1}
```

### Mid-stream ошибки

После того как итератор выдал хотя бы один ряд, заголовки уже сброшены —
HTTP-статус не поменять. Бандл дописывает inline error frame в активном
формате и закрывает поток чисто:

| Формат | Error frame |
|---|---|
| NDJSON | Финальная строка `{"error":{"code":...,"message":"..."}}` |
| SSE | `event: error\ndata: {"error":{...}}\n\n` |
| JsonArray | Финальный элемент `{"_error":{...}}` (подчёркнутый, чтобы не конфликтовать с валидными data) |

Клиенты должны проверять последний элемент на эту форму.

## Batch запрещён

`/rpc/stream` принимает **один** envelope. Batch даёт 400 с
`Streaming endpoint accepts only a single request`. Смешивать batch со
streaming'ом нет чистой wire-семантики — клиенты должны вызывать несколько раз.

## Notifications запрещены

Stream — не notification: он коррелирует запрос и ответ через `id`. Запрос
без `id` всё ещё стримит, но ответ не сматчишь. Это явно не отклоняется;
просто шлите `id` чтобы не нарваться.

## Сочетания с другими фичами

| Сочетание | Результат |
|---|---|
| `#[Rpc\Stream]` + `#[Rpc\Cache]` | **Compile-time error.** Stream per-call; нельзя переиграть из статичного blob'а. |
| `#[Rpc\Stream]` + `#[Rpc\RateLimit]` | Разрешено. Rate-limit срабатывает до старта итератора. |
| `#[Rpc\Stream]` + `#[Rpc\Mcp]` | Разрешено в метадате, но MCP-транспорт не стримит — LLM-клиент получает финальный aggregated content. |
| `#[Rpc\Stream]` + `#[Rpc\Method(roles: [...])]` | Разрешено. Auth срабатывает до старта итератора. |

## Заметки по производительности

- **Не накапливайте ряды в памяти.** Используйте generator-based handlers;
  yielded ряды эмитятся и освобождаются до следующего yield.
- **Не нормализуйте огромные структуры построчно.** Бандл нормализует каждый
  ряд через сериалайзер. Если ряд — массив на 10 МБ, каждый ряд платит цену.
  Держите ряды компактными.
- **HTTP/2 помогает.** Streams по HTTP/2 мультиплексируются с другими запросами;
  HTTP/1.1 держит коннект. Подгоните frontend.

## Пример: NDJSON stream

```php
#[Rpc\Method('logs.tail')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
final class LogsTail
{
    public function __construct(private LogReader $reader) {}

    public function __invoke(LogsTailRequest $req): \Generator
    {
        foreach ($this->reader->tail($req->source, $req->follow) as $line) {
            yield ['ts' => $line->timestamp, 'level' => $line->level, 'msg' => $line->message];
        }
    }
}
```

Reader эмитит один ряд на лог-строку. Клиент — `fetch` с reader'ом, который
сплитит по `\n`.

## Пример: SSE для прогресса

```php
#[Rpc\Method('export.progress')]
#[Rpc\Stream(format: StreamFormat::Sse)]
final class ExportProgress
{
    public function __invoke(ExportRequest $req): \Generator
    {
        foreach ($this->exporter->run($req) as $progress) {
            yield ['percent' => $progress];
        }
        yield ['done' => true, 'url' => $this->exporter->resultUrl()];
    }
}
```

Браузер:

```js
const es = new EventSource('/rpc/stream', { withCredentials: true });
es.onmessage = e => console.log(JSON.parse(e.data));
es.addEventListener('error', e => console.error('Stream error', e.data));
```

(SSE поверх POST требует кастомного транспорта — большинство приложений по
этой причине берут NDJSON.)
