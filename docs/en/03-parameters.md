# 03 — Parameters & DTOs

Three ways to receive parameters, picked by handler signature:

| Pattern | Signature | When to use |
|---|---|---|
| **DTO** | `__invoke(MyRequest $req)` | Anything with more than one field, especially anything you want validated. |
| **`#[Rpc\Param]`** | `__invoke(#[Rpc\Param] int $userId)` | One-or-two scalar inputs, or when a DTO feels heavy. |
| **`RpcRequest`** | `__invoke(RpcRequest $req)` | Schemas that vary at runtime, or proxies that forward verbatim. |

All three can be mixed with injected parameters (`Context`, `Request`).

## Pattern 1 — DTO

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
        // $req is fully validated; $req->email is guaranteed non-empty
        // and shaped like an email.
    }
}
```

The dispatcher:

1. Denormalizes the JSON object into the DTO via Symfony's
   `DenormalizerInterface`.
2. Validates the resulting instance via Symfony's `Validator` component.
3. Throws `InvalidParamsException` (-32602) on either failure, with a list of
   per-field violations in `error.data`.

### Rejecting unknown fields

By default unknown keys produce an `Invalid params` error:

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

Catches client typos. Turn it off per-method when you need backward-compat:

```php
#[Rpc\Method('user.legacy_get', rejectUnknown: false)]
```

Or globally:

```yaml
json_rpc_server:
  params:
    reject_unknown: false
```

### Positional params for a single-DTO method

By default a method that takes one DTO **requires named params** (`{...}`).
Positional (`[...]`) is rejected because it locks the DTO constructor argument
order into your public API.

To opt in:

```php
#[Rpc\Method('user.get', allowPositionalDto: true)]
```

Or globally:

```yaml
json_rpc_server:
  params:
    allow_positional_dto: true
```

When enabled, `"params": ["x@y", 25]` maps positionally onto the DTO's
constructor arguments.

## Pattern 2 — `#[Rpc\Param]`

For a method with one or two scalar inputs, a DTO feels heavy. Use
`#[Rpc\Param]`:

```php
#[Rpc\Method('user.findById')]
final class FindById
{
    public function __invoke(
        #[Rpc\Param('user_id')]                          // remap JSON key
        #[Assert\Positive]                                // standard validator
        int $userId,

        #[Rpc\Param('reason', required: false)]
        ?string $reason = null,

        Context $ctx,
    ): array {
        // $userId is validated (positive). $reason may be null.
    }
}
```

Effects:

- `name:` — JSON key used to look up the value
  (`{"user_id": 42}` ↔ `$userId`). Defaults to the PHP parameter name.
- Validator attributes (`#[Assert\Positive]`, `#[Assert\Email]`, etc.) on the
  same parameter are evaluated. Violations surface as -32602 errors with the
  parameter name in `path`.
- The parameter shows up in the method's MCP `inputSchema` and OpenRPC
  document — so even DTO-less methods are discoverable.

`required:` is informational for the JSON Schema. Whether the param is actually
mandatory is driven by the PHP signature: default value or nullable type makes
it optional.

## Pattern 3 — `RpcRequest`

For methods that need to inspect the raw envelope (custom routing, proxying,
generic schemas):

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

### `RpcParams` accessors

`$req->params` is an `RpcParams` object — a typed accessor over the JSON-RPC
`params` value. Modelled after Symfony's `InputBag`:

| Method | Returns | On missing key or null |
|---|---|---|
| `getString($key, $default)` | `string` | returns `$default` |
| `getInt($key, $default)` | `int` | returns `$default` |
| `getFloat($key, $default)` | `float` | returns `$default` |
| `getBool($key, $default)` | `bool` | returns `$default` |
| `getArray($key, $default)` | `array` | returns `$default` |
| `requireString($key)` | `string` | throws `InvalidParamsException` |
| `requireInt($key)` | `int` | throws `InvalidParamsException` |
| `requireFloat($key)` | `float` | throws `InvalidParamsException` |
| `requireBool($key)` | `bool` | throws `InvalidParamsException` |
| `requireArray($key)` | `array` | throws `InvalidParamsException` |

All typed getters are **strict**: a present value of the wrong shape raises
-32602, not silent coercion.

Positional access: `$req->params->at(0)`, `$req->params->isList()`,
`$req->params->count()`.

## Injected parameters

These are recognised by type and resolved by the dispatcher — they never come
from the JSON envelope:

| Type | What it is |
|---|---|
| `Knetesin\JsonRpcServerBundle\Context\Context` | Per-call context: `methodName`, `requestId`, `user`, `roles`. See [Context](./14-context.md). |
| `Symfony\Component\HttpFoundation\Request` | The HTTP request. Resolved from `RequestStack`. Throws if there's no active request (e.g. unit test out of context). |
| `Knetesin\JsonRpcServerBundle\Request\RpcRequest` | The decoded JSON-RPC envelope. |

You can mix and match — `__invoke(MyDto $req, Context $ctx, Request $http)`
all work together.

## Dates and date-times

The bundle ships `Knetesin\JsonRpcServerBundle\Type\Date` for "date without time" (because
PHP doesn't have one):

```php
final readonly class CreateEventRequest
{
    public function __construct(
        public Date $startsOn,                  // date only
        public \DateTimeImmutable $startsAt,    // date+time
    ) {}
}
```

Input is lenient — the bundle accepts ISO, custom formats, "yesterday", or unix
timestamps depending on configuration:

```yaml
json_rpc_server:
  serializer:
    datetime_format: 'iso8601'   # or 'timestamp' / 'timestamp_ms' / custom php date()
    date_format: 'Y-m-d'
    timezone: 'UTC'
```

For example, with `datetime_format: timestamp_ms`:

- Output: `DateTimeImmutable` → integer (Unix ms)
- Input: any of `1773483072345`, `"2026-03-14T10:11:12+00:00"`, `"yesterday"`
- MCP/OpenRPC schemas advertise `{type: 'integer'}` correctly

Full details in [Configuration reference](./13-configuration.md#serializer).
