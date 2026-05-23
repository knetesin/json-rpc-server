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
| Access denied | 200 | 400 | 400 | 200 (MCP convention) |
| Rate limit | 200 | 429 | 400 | 200 (MCP convention) |
| Internal error | 200 | 500 | 500 | 200 (MCP convention) |
| Request too large | **413** | **413** | **413** | **413** |

On `/rpc`, oversized payloads always return **413** — even when
`http_status.enabled` is `false`. That lets monitoring and load balancers drop
oversize traffic without parsing JSON.

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
