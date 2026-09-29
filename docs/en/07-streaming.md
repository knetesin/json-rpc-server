# 07 — Streaming

The bundle exposes a streaming endpoint at `/rpc/stream` that mirrors the
JSON-RPC envelope on input and emits rows over time. This is **not** JSON-RPC
2.0 (the spec is request/response only) — it's a deliberate extension for
LLM token streaming, server-sent updates, progressive lists, etc.

## Enabling

The streaming route is **off by default** — flip it on when you actually have
a `#[Rpc\Stream]` handler:

```yaml
json_rpc_server:
  routes:
    stream: { enabled: true }
```

If you forget, the compiler pass throws a clear `LogicException` at container
build listing every method that carries the attribute — no silent 404s.

## Declaring a streaming method

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

Return type must be `iterable<mixed>` (Generator works best — produces one row
at a time without holding everything in memory).

## Formats

| Format | Content-Type | Wire format |
|---|---|---|
| `StreamFormat::Ndjson` (default) | `application/x-ndjson` | One JSON object per line. |
| `StreamFormat::Sse` | `text/event-stream` | `data: <json>\n\n` framing. Browser EventSource works. |
| `StreamFormat::JsonArray` | `application/json` | `[<json>,<json>,...]` — single valid JSON document, progressively written. |

```php
#[Rpc\Stream(format: StreamFormat::Ndjson)]
#[Rpc\Stream(format: StreamFormat::Sse)]
#[Rpc\Stream(format: StreamFormat::JsonArray)]
```

## Calling

```bash
curl -X POST http://localhost/rpc/stream \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"chat.stream","params":{"prompt":"…"},"id":1}'
```

Response headers always include:
- `Cache-Control: no-cache`
- `X-Accel-Buffering: no` (tells nginx/Cloudflare not to buffer)

The bundle also calls `ob_flush()` + `flush()` per row, which means streams
actually stream under PHP-FPM with default `output_buffering = 4096`.

A streaming method is callable **only** through `/rpc/stream`. On `/rpc` —
as a single call, a batch item or a parallel-batch sub-call — it is rejected
before dispatch with `-32600 Invalid Request` (`Method chat.stream is a
streaming method; call it via the streaming endpoint`); the handler never
runs. A notification to it gets no response and does not run either.

## Error handling

The endpoint distinguishes pre-stream errors from mid-stream errors:

### Pre-stream errors

Detected before the iterator starts (parse, method-not-found, batch > 1,
method-not-streaming, access denied, rate limit, invalid params, …). Result:
plain JSON-RPC envelope, HTTP 4xx/5xx mapped from `error.code`. This endpoint
always maps statuses, regardless of `http_status.enabled`:

| Failure | Status |
|---|---|
| Parse / Invalid Request | 400 |
| Invalid params | 400 |
| Access denied (-32001) | 403 |
| Method not found / Not found (-32002) | 404 |
| Request body over the size limit | 413 |
| Rate limit (-32003) | 429 + `Retry-After` |
| Internal error | 500 |
| Other codes in -32099 … -32000 | 400 |
| Any other code | 500 |

```json
{"jsonrpc":"2.0","error":{"code":-32600,"message":"Streaming endpoint accepts only a single request"},"id":1}
```

An unexpected (non-`RpcException`) error raised before the iterator starts —
for example one thrown by a [method guard](./04-security.md#method-guards) —
returns HTTP 500 with the same JSON-RPC envelope (`error.code: -32603`)
instead of the framework's HTML error page.

### Mid-stream errors

Once the iterator has emitted at least one row, headers are already flushed —
HTTP status can't change. The bundle appends an inline error frame in the
active format and closes the stream cleanly:

| Format | Error frame |
|---|---|
| NDJSON | Final line `{"error":{"code":...,"message":"..."}}` |
| SSE | `event: error\ndata: {"error":{...}}\n\n` |
| JsonArray | Final element `{"_error":{...}}` (underscored to avoid colliding with valid data shapes) |

Clients should always check the last item for this shape.

## Batching is forbidden

`/rpc/stream` accepts a **single** envelope. A batch yields a 400 with
`Streaming endpoint accepts only a single request`. Mixing batch with
streaming has no clean wire semantics — clients should call multiple times.

## Notifications are forbidden

Streams aren't notifications — they correlate request and response via `id`.
A request without `id` still streams, but the response can't be matched.
We don't reject this explicitly; just send `id` to be safe.

## Combining with other features

| Combination | Result |
|---|---|
| `#[Rpc\Stream]` + `#[Rpc\Cache]` | **Compile-time error.** A stream is per-call; can't be replayed from a static blob. |
| `#[Rpc\Stream]` + `#[Rpc\RateLimit]` | Allowed. Rate-limit fires before the iterator starts. |
| `#[Rpc\Stream]` + `#[Rpc\Mcp]` | Allowed in metadata, but streaming methods are never exposed over MCP (not with `#[Rpc\Mcp]`, `expose_all` or `whitelist_methods`): `/mcp/tools` omits them and `/mcp/call` answers 404 like for an unknown tool. |
| `#[Rpc\Stream]` + `#[Rpc\Method(roles: [...])]` | Allowed. Auth fires before the iterator starts. |

## Performance notes

- **Don't accumulate rows in memory.** Use generator-based handlers; yielded
  rows are emitted and freed before the next yield.
- **Don't normalize huge structures per row.** The bundle normalizes each
  yielded item through the serializer. If your row is a 10-MB array, every
  row pays serializer cost. Keep rows lean.
- **HTTP/2 helps.** Streams over HTTP/2 multiplex with other requests; HTTP/1.1
  holds the connection. Adjust your front-end accordingly.

## Example: NDJSON stream

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

Reader emits one row per log line. Client uses `fetch` with a reader that
splits on `\n`.

## Example: SSE for progress

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

Browser:

```js
const es = new EventSource('/rpc/stream', { withCredentials: true });
es.onmessage = e => console.log(JSON.parse(e.data));
es.addEventListener('error', e => console.error('Stream error', e.data));
```

(SSE over POST needs custom transport — most apps use NDJSON for that reason.)
