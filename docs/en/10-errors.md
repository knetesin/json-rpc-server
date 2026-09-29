# 10 — Errors

## JSON-RPC error envelope

Standard 2.0 shape, always:

```json
{
  "jsonrpc": "2.0",
  "error": { "code": -32602, "message": "Invalid params", "data": [...] },
  "id": 1
}
```

`data` is optional and varies by exception class. Validation errors carry a
list of violations; rate limit errors carry `retryAfter`; etc.

## Standard codes

Per JSON-RPC 2.0 §5.1:

| Code | Meaning | Bundle class |
|---|---|---|
| -32700 | Parse error | `ParseException` |
| -32600 | Invalid request | `InvalidRequestException`, `RequestTooLargeException` |
| -32601 | Method not found | `MethodNotFoundException` |
| -32602 | Invalid params | `InvalidParamsException` |
| -32603 | Internal error | `InternalErrorException` |
| -32099 … -32000 | Server-defined range | reserve your own |

## Bundle-defined codes

In the server-defined range:

| Code | Meaning | Class |
|---|---|---|
| -32001 | Access denied | `AccessDeniedException` |
| -32002 | Not found (entity-level) | `NotFoundException` |
| -32003 | Rate limit exceeded | `RateLimitExceededException` |

These constructors accept overrides if your protocol contract uses different
codes:

```php
throw new AccessDeniedException('No access to billing', rpcCode: -33001);
```

## Throwing your own

Subclass `RpcException`:

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

In your handler:

```php
throw new PaymentDeclinedException('Card declined', bankCode: 'INSUFFICIENT_FUNDS');
```

Wire shape:

```json
{
  "error": {
    "code": -32010,
    "message": "Card declined",
    "data": {"bankCode": "INSUFFICIENT_FUNDS"}
  }
}
```

## Validation errors

`InvalidParamsException` (-32602) carries a list of violations in `data`:

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

Each entry: `{path, message, code}`. The `code` is Symfony's validator constraint UUID; useful for i18n.

Sources of violations:

- DTO denormalization errors (type mismatch, missing required, etc.)
- Symfony Validator constraint violations on the DTO
- Validator constraints on `#[Rpc\Param]` scalar params

The MCP endpoint additionally renders these into the `content[0].text` block:

```
Error -32602: Invalid params
  - email: This value is not a valid email address.
  - age: This value should be between 0 and 150.
```

Even text-only LLM clients see what went wrong.

## Rate limit errors

`RateLimitExceededException` (-32003) carries `retryAfter`:

```json
{
  "error": {
    "code": -32003,
    "message": "Rate limit exceeded for billing.heavy",
    "data": {"retryAfter": 42}
  }
}
```

The HTTP response also includes `Retry-After: 42` so HTTP-level clients can
back off without parsing the body.

## HTTP statuses

JSON-RPC 2.0 is HTTP-status-agnostic in spirit — every response could be a 200
with an error in the body. The bundle leans pragmatic:

| Failure | `/rpc` (default) | `/rpc` + `http_status.enabled` | `/rpc/stream` (pre-stream) | `/mcp/call` |
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

Other codes in the server-defined range (-32099 … -32000) map to 400, any
other code to 500. `/rpc/stream` always maps pre-stream errors this way,
whatever `http_status.enabled` says, and adds `Retry-After` to a rate-limit
rejection.

On `/rpc`, **413** means nothing ran, and it is returned even when
`http_status.enabled` is `false`: either the body exceeds the parser cap (the
largest of `max_request_size` and every `#[Rpc\MaxRequestSize]`), or a single
(non-batch) request exceeds its method's limit. That lets monitoring and load
balancers drop oversize traffic without parsing JSON. A batch is never
answered with 413 once it has been parsed — see below.

For every other failure, the JSON-RPC body's `error.code` is the canonical
classifier. Optional HTTP mapping is dev-friendly (browser, `curl -f`, proxies)
but off by default so JSON-RPC clients and retry middleware keep seeing a
uniform 200:

```yaml
json_rpc_server:
  http_status:
    enabled: true
```

Batch responses use the **highest** HTTP status among items (e.g. one 404 and
one 200 → 404). Successful items still carry `result` in the body.

## Per-item errors in a batch

A batch item that cannot run gets its own error entry; the other items still
run and the HTTP status follows the rules above (200 by default):

- **Invalid item** (not an object, missing `jsonrpc: "2.0"` or `method`, bad
  `id`/`params` type) → `{"jsonrpc":"2.0","error":{"code":-32600,...},"id":null}`.
  Such an item always gets an entry, because it cannot be recognized as a
  notification. `[1,2,3]` yields three -32600 errors.
- **Item over its method's `MaxRequestSize`** → -32600 with the item's `id`.
  The item is measured by its own size (its re-encoded JSON envelope), not by
  the whole batch body; the whole body is still bounded by the parser cap.
- **Streaming method** (`#[Rpc\Stream]`) → -32600 pointing at the streaming
  endpoint.

Rejected notifications (valid shape, no `id`) get no entry. Rejected items
never reach the handler or the parallel-batch executor.
`BatchDispatchedEvent::$batchSize` counts every entry of the request array,
including rejected ones.

A payload-level problem is still answered with a single error object: invalid
JSON (-32700), an empty batch `[]`, or an invalid non-batch request (-32600).

## Internal errors

Any uncaught `\Throwable` from a handler becomes `InternalErrorException`
(-32603) on the wire. The original exception is logged via PSR-3 (`error`
level) with the full stack trace before the envelope is built. Clients see only
`"Internal error"`, never the message of the original exception — prevents
accidental info leaks (DB connection strings, etc.).

If you want a different leak boundary, throw your own `RpcException` subclass
explicitly:

```php
try {
    $this->somethingDelicate->run();
} catch (DatabaseException $e) {
    throw new InternalErrorException('Service temporarily unavailable', previous: $e);
}
```

## Notifications and errors

JSON-RPC 2.0 says notifications never produce a response, even on error.
The bundle honors this — exceptions still bubble up as logs and `Failed`
events, but no envelope hits the client. The HTTP response is `204 No Content`.
