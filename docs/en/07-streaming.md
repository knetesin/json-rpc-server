# 07 — Streaming

The bundle exposes a streaming endpoint at `/rpc/stream` that mirrors the
JSON-RPC envelope on input and emits rows over time. This is **not** JSON-RPC
2.0 (the spec is request/response only) — it's a deliberate extension for
LLM token streaming, server-sent updates, progressive lists, etc.

## Declaring a streaming method

```php
use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\StreamFormat;

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

## Error handling

The endpoint distinguishes pre-stream errors from mid-stream errors:

### Pre-stream errors

Detected before the iterator starts (parse, method-not-found, batch > 1,
method-not-streaming). Result: plain JSON-RPC envelope, HTTP 4xx/5xx.

| Failure | Status |
|---|---|
| Parse / Invalid Request | 400 |
| Method not found | 404 |
| Internal error | 500 |

```json
{"jsonrpc":"2.0","error":{"code":-32600,"message":"Streaming endpoint accepts only a single request"},"id":1}
```

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
| `#[Rpc\Stream]` + `#[Rpc\Mcp]` | Allowed in metadata, but MCP transport doesn't stream — the LLM client receives the final aggregated content. |
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
