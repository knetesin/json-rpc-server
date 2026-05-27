# 02 — Methods

A method is a class that:

1. Carries `#[Rpc\Method('name')]`
2. Defines `__invoke()` — anything callable, anything serializable returned

The compiler pass discovers them via auto-configuration. No manual service
registration, no central method registry to keep in sync.

## The attribute

```php
#[Rpc\Method(
    name: 'user.getByEmail',
    roles: ['ROLE_USER'],                     // see Security chapter
    rolesMatch: RoleMatch::Any,               // any | all
    allowPositionalDto: false,                // see Parameters chapter
    rejectUnknown: true,                      // see Parameters chapter
    deprecated: 'Use user.find instead.',     // marks as deprecated
    description: 'Looks up a user by email.', // human-readable; used in MCP
    outputSchema: UserDto::class,             // optional: see MCP / OpenRPC chapters
)]
```

All fields beyond `name` are optional. `null`-valued ones fall back to bundle
defaults (e.g. `params.allow_positional_dto`).

## The handler

```php
#[Rpc\Method('user.getByEmail')]
final class GetUserByEmail
{
    public function __construct(
        // Inject services like any other Symfony service.
        private readonly UserRepository $users,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(GetUserByEmailRequest $req, Context $ctx): array
    {
        $user = $this->users->findOneByEmail($req->email)
            ?? throw new NotFoundException("No user for {$req->email}");

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
        ];
    }
}
```

Handlers are non-shared services — every request gets a fresh instance. Safe
for long-running workers (RoadRunner, Octane, Swoole): no leaked state between
users.

## Return values

The dispatcher normalizes return values through Symfony's `SerializerInterface`
before they reach the client. This means handlers can return:

- Plain arrays / scalars / nulls — returned as-is
- DTOs — denormalized into arrays using the configured normalizers
- Doctrine entities — serialized per your serialization groups
- Anything implementing `JsonSerializable`

The normalized form is also what gets cached and what events carry, so listeners
see the same shape regardless of whether a hit came from cache or from a fresh
call.

Skip normalization for streaming methods — see [Streaming](./07-streaming.md).

## Batch requests

The `/rpc` endpoint accepts both single objects and arrays of objects per the
JSON-RPC 2.0 spec:

```json
[
  {"jsonrpc":"2.0","method":"math.add","params":[1,2],"id":1},
  {"jsonrpc":"2.0","method":"math.add","params":[3,4],"id":2}
]
```

Returns an array of responses in the same order. Notifications (entries without
`id`) are processed but don't produce response entries. If every entry is a
notification, the HTTP response is `204 No Content`.

### Batches are processed sequentially by default

The dispatcher walks the array in order and runs each item to completion before
the next one starts — all in one PHP process. A batch of N items finishes in
roughly `sum of per-handler durations`, not `max`. Batches save the **network**
overhead (one HTTP request, one parser pass, one auth check), not handler time.

For real concurrency, clients should fire N separate HTTP requests in parallel
(e.g. [json-rpc-client's `callAsync`](https://github.com/knetesin/json-rpc-client))
— PHP-FPM / RoadRunner / Swoole then dispatch each request to a different
worker and the calls run truly in parallel.

### Opt-in: parallel batches via loopback fan-out

Per JSON-RPC 2.0 §6, a server _may_ process a batch in any order and with any
parallelism. The bundle ships an opt-in implementation that does exactly that
by sending each batch item back to itself as a separate HTTP request — the
worker pool then runs handlers in parallel.

```yaml
json_rpc_server:
    parallel_batch:
        enabled: true               # off by default
        max_concurrency: 3          # max parallel sub-calls per batch
        budget: 10                  # system-wide cap (APCu-backed)
        max_depth: 1                # no fan-out from a sub-call
        connect_timeout: 0.5
        timeout: 10
        self_url: ~                 # null = derive from the incoming request
```

**Real operational risk.** A naive setup can starve your worker pool. The
bundle ships **five safety layers** to mitigate, but **measure first** before
enabling in production:

1. Per-batch `max_concurrency` cap.
2. System-wide `budget` via APCu — never more than N sub-calls in flight
   across all parents.
3. Recursion guard (`X-Rpc-Fanout-Depth` header) — sub-calls can't fan out
   again.
4. Per-sub-call timeout fallback — a stuck sub-call becomes one error,
   not a stuck batch.
5. Recommended deployment: dedicated worker pool for fan-out
   (`self_url: 'http://127.0.0.1/internal/rpc-fanout'`) so fan-out can't
   exhaust the client-traffic pool.

When fan-out can't proceed (HttpClient missing, APCu missing, batch too small,
depth limit reached, budget exhausted), the controller transparently falls
back to sequential processing — clients see no difference except possibly
higher latency on that one batch. The `BatchDispatchedEvent` carries the
decision label (visible in the Web Profiler and OpenTelemetry traces) so you
can monitor exactly when fallback is firing.

Requires `symfony/http-client` (hard) and `ext-apcu` (soft). When
`parallel_batch.enabled: true` and `budget_store: apcu` (the default) but
APCu isn't loaded, the bundle falls back to `NullBudgetTracker` and emits
an `E_USER_WARNING` at container build time — the system-wide budget is
**off** in that mode, so on FPM you risk pool exhaustion under load. To
silence the warning when you intentionally don't want a global cap, set
`budget_store: null` explicitly.

## Notifications

A request without `id` is a notification:

```json
{"jsonrpc":"2.0","method":"audit.log","params":{"event":"login"}}
```

The handler runs, no response body is sent, the HTTP response is `204`. Even
if the handler throws, no error envelope is returned (per spec). The exception
is still logged and dispatched as a `MethodInvocationFailedEvent`.

Notifications are **not** cached even if the method has `#[Rpc\Cache]` — they
typically carry side effects you want to re-apply each time.

## Deprecation

```php
#[Rpc\Method('user.legacy_get', deprecated: 'Use user.get instead.')]
```

Effects:

1. **Logger.** Every call emits a `warning` with `method` and `reason`.
2. **HTTP headers.** Responses carry `Deprecation: true` and
   `X-Rpc-Deprecated: user.legacy_get: Use user.get instead.`
3. **MCP.** Deprecated methods are hidden from `/mcp/tools` unless explicitly
   whitelisted — LLM agents shouldn't pick them up as fresh tools.
4. **OpenRPC.** Method emitted with `"deprecated": true` and a custom
   `x-deprecation-reason` field.

## Common patterns

### Public method (no roles)

```php
#[Rpc\Method('public.ping')]
final class Ping
{
    public function __invoke(): array { return ['pong' => true]; }
}
```

### Protected method

```php
#[Rpc\Method('user.delete', roles: ['ROLE_ADMIN'])]
final class DeleteUser { /* … */ }
```

See [Security & roles](./04-security.md).

### Method that needs the raw HTTP request

```php
public function __invoke(Request $request, Context $ctx): array
{
    $ip = $request->getClientIp();
    // …
}
```

`Symfony\Component\HttpFoundation\Request` is recognized as an injectable
parameter — the bundle wires it from `RequestStack`.

### Method that needs the JSON-RPC envelope

```php
public function __invoke(RpcRequest $req, Context $ctx): array
{
    // $req->id, $req->method, $req->params, $req->isNotification
}
```

`Knetesin\JsonRpcServerBundle\Request\RpcRequest` is also injectable.

### One method, multiple shapes

JSON-RPC method names form a flat namespace. Use prefixes for grouping:

```php
#[Rpc\Method('user.get')]
#[Rpc\Method('user.update')]
#[Rpc\Method('user.delete')]
```

Versioning works the same way — see the
[OpenRPC chapter](./09-openrpc.md#versioning-strategies).
