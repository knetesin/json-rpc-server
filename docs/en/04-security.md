# 04 — Security & roles

Two layers:

1. **Authentication** — who is calling. Driven by your Symfony firewall.
2. **Authorization** — what they can call. Driven by per-method `roles`.

The bundle handles authorization. Authentication stays a firewall concern; the
bundle never inspects credentials directly.

## Public methods

By default, omitting `roles` makes the method public — the dispatcher skips
authorization entirely:

```php
#[Rpc\Method('public.ping')]
final class Ping
{
    public function __invoke(): array { return ['pong' => true]; }
}
```

Anonymous requests pass through, **provided your firewall also allows them on
the `/rpc` route.**

> **Switching to secure-by-default.** Set `security.default_roles` (see
> [Configuration reference](./13-configuration.md#securitydefault_roles--public_prefixes--public_methods--prefix_roles))
> to flip the default: every method without explicit `roles:` inherits the
> listed roles, and only `public_prefixes` / `public_methods` stay anonymous.
> Use `prefix_roles` (e.g. `admin.* → ROLE_ADMIN`) to apply per-prefix defaults
> without putting `roles:` on every handler.

## Protected methods

```php
#[Rpc\Method('user.delete', roles: ['ROLE_ADMIN'])]
final class DeleteUser { /* … */ }
```

On call, the dispatcher checks `AuthorizationCheckerInterface::isGranted()`
against each role. Missing role → throws `AccessDeniedException` (-32001).

If `symfony/security-bundle` isn't installed but a method declares `roles`, the
bundle throws at the first call with a clear "install symfony/security-bundle"
message — no silent bypass.

## Multiple roles: any vs all

```php
// Any (default) — at least one role matches.
#[Rpc\Method('billing.refund', roles: ['ROLE_SUPPORT', 'ROLE_ADMIN'])]

// All — every role must match.
#[Rpc\Method(
    'compliance.export',
    roles: ['ROLE_ADMIN', 'ROLE_COMPLIANCE'],
    rolesMatch: RoleMatch::All,
)]
```

Change the default for methods that omit `rolesMatch`:

```yaml
json_rpc_server:
  security:
    roles_match: all   # or 'any'
```

## Hiding role names in error messages

The default `AccessDenied` message names the missing role(s):

```
One of the following roles is required: ROLE_BILLING_INTERNAL_ADMIN
```

Helpful in dev. In prod, some teams treat role identifiers as internal —
flip the config knob:

```yaml
json_rpc_server:
  security:
    expose_role_names: false
```

Now the message is just `Access denied`. The HTTP body still carries
`error.code: -32001`, just without the leak.

The same flag also removes role identifiers from discovery: `/mcp/tools`
entries carry no `roles`, and the OpenRPC document carries no `x-rpc-roles` /
`x-rpc-roles-match`.

## Method guards

Roles express static access rules ("has `ROLE_ADMIN`"). Some rules need the
resolved arguments — "may this caller edit *this* group" — and that's what
`MethodGuardInterface` is for:

```php
namespace Knetesin\JsonRpcServerBundle\Security;

use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;

interface MethodGuardInterface
{
    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void;
}
```

Implement the interface in a service — it's auto-tagged
`json_rpc_server.method_guard`, no manual wiring needed. `$args` is the
resolved `__invoke()` argument list keyed by parameter name, in declaration
order (a DTO instance or scalar values) — the same instances the handler
receives, so treat them as read-only. Throw to deny.

### Registration and order

Several guards can be registered; they run in tag priority order, higher
first:

```php
#[AsTaggedItem(priority: 10)]
final class GroupAccessGuard implements MethodGuardInterface { /* … */ }
```

The first exception thrown stops the chain — guards after it do not run.

Without autoconfiguration, tag the service `json_rpc_server.method_guard`
yourself, with an optional `priority` — in service config or from any
compiler pass:

```yaml
services:
    App\Security\GroupAccessGuard:
        tags:
            - { name: json_rpc_server.method_guard, priority: 10 }
```

The tagged class must implement `MethodGuardInterface`, otherwise the
container build fails.

### Where guards run

Roles → rate limit → argument resolution + validation → **guards** → cache
lookup → handler. Guards run on every dispatch path: single calls, every
batch item, notifications (a denial produces no response — the JSON-RPC rule
for notifications), streaming (before the handler/iterator is invoked; a
pre-stream denial returns the usual JSON-RPC envelope with the HTTP status
mapped from its code (e.g. 403 for -32001, 404 for -32002) — see
[Streaming](./07-streaming.md)), MCP `tools/call` (HTTP 200 with
`isError: true`, per MCP convention), and parallel-batch sub-calls (each
sub-call goes through the same dispatcher). Guards run before the cache
lookup, so **a cache hit never skips them** — see
[Method guards and cache lookups](./05-caching.md#method-guards-and-cache-lookups).

With parallel batch enabled, each item runs as an internal HTTP sub-request
back to the same app. Guards run there too, but the HTTP request they see via
`RequestStack` carries only the headers listed in
`parallel_batch.forward_headers`, and the connection comes from the server
itself: `Request::getClientIp()` returns the server's own address — or, if
the app trusts its own address as a proxy, whatever the forwarded
`X-Forwarded-For` says. The original client IP travels in a signed internal
header (see [parallel batches](./02-methods.md#opt-in-parallel-batches-via-loopback-fan-out));
the bundle's `RateLimitScope::Ip`, guest `RateLimitScope::User` and `IpScope`
already use it, and a guard that needs the client IP should read it from
`Knetesin\JsonRpcServerBundle\Http\ClientIpResolver::clientIp()`, which
returns the verified original IP in a sub-call and `getClientIp()` otherwise.
Otherwise base guard decisions on the security token and the RPC arguments,
not on arbitrary headers.

### Errors

An `RpcException` thrown from a guard reaches the client with its own code,
message and `rpcData()`. Any other exception becomes `-32603 Internal error`
(and is logged). Either way, `MethodInvocationFailedEvent` fires. With
`http_status.enabled`, the HTTP status follows the error code — see
[Errors](./10-errors.md#http-statuses); e.g. `NotFoundException`'s
default code -32002 maps to 404, `AccessDeniedException`'s default -32001
maps to 403. The streaming endpoint maps pre-stream errors this way even when
`http_status.enabled` is off.

### Reading the handler's attributes

`$meta->getAttributes(SomeAttribute::class)` returns instances of the
handler **class's** attributes (not `__invoke()`'s, and not inherited from
parent classes), pre-built at container compile time — no runtime
reflection. They're collected only when at least one guard is registered;
`$meta->attributes` stays empty otherwise. The bundle's own `Rpc\*`
attributes and `Symfony\Component\DependencyInjection\Attribute\*` are left
out; an attribute whose class isn't loaded is skipped.

Every other class-level attribute on a handler must be instantiable (valid
target, repeatable rules, constructor) and take only scalar, array, enum or
null constructor arguments — the container has to be able to dump it — and
array keys in those arguments must not contain `%` (the container would read
them as `%parameter%` placeholders). Otherwise the container build fails,
naming the offending method and attribute.

### Example

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Requires
{
    public function __construct(
        public Permission $permission,
        public string $on,
    ) {}
}
```

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('group.rename')]
#[Requires(Permission::GroupEdit, on: 'groupId')]
final class RenameGroup
{
    public function __invoke(RenameGroupRequest $request): void { /* … */ }
}
```

```php
use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Security\MethodGuardInterface;

final class RequiresPermissionGuard implements MethodGuardInterface
{
    public function __construct(private PermissionChecker $permissions) {}

    public function check(MethodMetadata $meta, array $args, RpcRequest $request): void
    {
        foreach ($meta->getAttributes(Requires::class) as $requires) {
            $groupId = $args[$requires->on] ?? $args['request']->{$requires->on};
            if (!$this->permissions->has($requires->permission, $groupId)) {
                throw new AccessDeniedException('Group access denied');
            }
        }
    }
}
```

`AccessDeniedException` takes `(string $message, int $rpcCode = -32001)` —
pass whichever JSON-RPC error code the client should see (mind the HTTP
mapping above when `http_status.enabled`), or throw your own `RpcException`
subclass with a custom `rpcData()`.

## Firewall configuration

The bundle ships nothing for the firewall side. Typical setup if your `/rpc` is
JWT-authenticated:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        rpc:
            pattern: ^/rpc
            stateless: true
            jwt: ~
        # or whatever your auth scheme is
```

Whatever ends up in the token storage as the `UserInterface` becomes
`Context::$user` and feeds `RoleMatch` checks.

## Working with the user inside a handler

```php
public function __invoke(MyRequest $req, Context $ctx): array
{
    $userId = $ctx->user?->getUserIdentifier();    // null for anon
    $isAdmin = $ctx->hasRole('ROLE_ADMIN');
    // …
}
```

`Context` is read-only and per-call. See [Context](./14-context.md).

## Cache scopes by user

If you're using `#[Rpc\Cache]`, the bundle ships `UserScope` so cached
entries are keyed per user identifier:

```php
#[Rpc\Method('user.profile', roles: ['ROLE_USER'])]
#[Rpc\Cache(ttl: 60, scope: UserScope::class)]
final class GetMyProfile { /* … */ }
```

Guests share a single `guest` slot, keyed apart from every `user:<identifier>`
slot — a user whose identifier happens to be `anon` or `guest` never shares
entries with anonymous callers.

See [Caching](./05-caching.md#built-in-scopes).

## Rate limiting by user

`RateLimitScope::User` keys the rate-limit counter on the user identifier:

```php
#[Rpc\Method('billing.heavyReport', roles: ['ROLE_USER'])]
#[Rpc\RateLimit(limit: 5, intervalSec: 60, scope: RateLimitScope::User)]
final class HeavyReport { /* … */ }
```

Authenticated users get one bucket each (`user:<identifier>`). Guests are
limited **per client IP** (`guest-ip:<ip>`), so one anonymous client cannot
exhaust the limit for every other guest, and no real user shares a bucket with
guests. Inside parallel-batch sub-calls the IP is the original client's,
taken from the signed fan-out header.

## Security checklist

- ✅ Firewall covers `/rpc`, `/mcp/call`, `/rpc/stream`
- ✅ Roles on every non-public method, `rolesMatch: All` for high-risk methods
- ✅ `expose_role_names: false` in production
- ✅ Rate limit anonymous endpoints (`scope: Ip`)
- ✅ `max_request_size` set to your maximum acceptable payload (default 1 MB)
- ✅ MCP traffic — keep `mcp.apply_rate_limit: true` (the default); set it to
  `false` only when `/mcp/call` is reachable exclusively by a trusted internal agent
