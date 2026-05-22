# 14 — Context

`Context` is a read-only object the bundle assembles per call. Inject it like
a typed parameter:

```php
use JsonRpcServer\Context\Context;

#[Rpc\Method('user.profile')]
final class GetProfile
{
    public function __invoke(Context $ctx): array
    {
        return [
            'who' => $ctx->user?->getUserIdentifier() ?? 'anonymous',
            'when' => $ctx->methodName,
            'requestId' => $ctx->requestId,
        ];
    }
}
```

## Shape

```php
final readonly class Context
{
    public string $methodName;
    public string $requestId;
    public ?\Symfony\Component\Security\Core\User\UserInterface $user;
    /** @var list<string> */
    public array $roles;

    public function hasRole(string $role): bool;
}
```

| Field | Source |
|---|---|
| `methodName` | The JSON-RPC method name being invoked. |
| `requestId` | First non-empty: cached `_rpc_request_id` attribute → configured request-id header (default `X-Request-Id`) → freshly generated `bin2hex(random_bytes(8))` (16 hex chars). |
| `user` | The current `UserInterface` from token storage, or `null` if anonymous / no security-core. |
| `roles` | List of granted role names from the current token, or empty. |
| `hasRole($r)` | Convenience for `in_array($r, $roles, true)`. |

`requestId` is **cached** back into the HTTP request attributes after first
resolution. In a batched JSON-RPC call (5 methods in one HTTP request), all 5
`Context` instances share the same `requestId` — useful for correlating logs
and audit entries across a batch.

## Setting the request id externally

The bundle reads the configurable HTTP header on every request — by default
`X-Request-Id`. An API gateway or load balancer can pin its own correlation
id end-to-end with no app code:

```
X-Request-Id: 9f4a-mobile-…
```

Change the header name (e.g. for `Trace-Id`) in config:

```yaml
json_rpc_server:
    context:
        request_id_header: 'Trace-Id'   # set '' to disable header lookup entirely
```

If your app produces an id from inside a Symfony listener (rather than via
HTTP header), set it on the request attribute before the bundle reads it:

```php
// In an EventListener on kernel.request, early priority:
$request->attributes->set('_rpc_request_id', $traceId);
```

The bundle then uses that value instead of generating a new one.

## When `Context::$user` is null

- No active HTTP request (e.g. CLI worker calling the dispatcher directly)
- `symfony/security-core` not installed
- Anonymous request (no firewall token)
- Token's `getUser()` doesn't return a `UserInterface`

Handlers should treat null user as "anonymous" and not as "logged in user
without identifier".

## Differences vs `RpcRequest`

| | `Context` | `RpcRequest` |
|---|---|---|
| What it is | Per-call session info (who, when, what method) | The raw JSON-RPC envelope (`id`, `method`, `params`, `isNotification`) |
| When to use | You need user / roles / request id | You need to inspect params programmatically or forward |
| Mutable? | No | No |

You can inject both:

```php
public function __invoke(MyRequest $req, Context $ctx, RpcRequest $envelope): array
{
    if ($envelope->isNotification) {
        // …
    }
    if ($ctx->hasRole('ROLE_ADMIN')) {
        // …
    }
}
```

## Inside cache / rate-limit scopes

`Context` is built once per dispatch and reused. Cache and rate-limit scopes
read from the same `RequestStack` and `TokenStorage` directly, so they're
consistent with what `Context::$user` returns.

For example: a rate limit `scope: User` and a `Context::$user` resolve to the
same `getUserIdentifier()` — no risk of "rate limited as user X, audited as
user Y" mismatches mid-request.

## Logging pattern

A common pattern: prepend `requestId` to every log line in handlers.

```php
public function __invoke(MyRequest $req, Context $ctx): array
{
    $this->logger->info('Processing request', [
        'request_id' => $ctx->requestId,
        'method' => $ctx->methodName,
        'user' => $ctx->user?->getUserIdentifier(),
    ]);
    // …
}
```

For batch operations, all entries share the `requestId` so grep finds them
together.

A Monolog processor can do this automatically — feed it `RequestStack` and
read `_request_id` from the current request's attributes.
