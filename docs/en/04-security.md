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

See [Caching](./05-caching.md#built-in-scopes).

## Rate limiting by user

`RateLimitScope::User` keys the rate-limit counter on the user identifier:

```php
#[Rpc\Method('billing.heavyReport', roles: ['ROLE_USER'])]
#[Rpc\RateLimit(limit: 5, intervalSec: 60, scope: RateLimitScope::User)]
final class HeavyReport { /* … */ }
```

Anonymous callers all share the same `anon` slot — typically that's what you
want (rate-limit anonymous traffic harshly).

## Security checklist

- ✅ Firewall covers `/rpc`, `/mcp/call`, `/rpc/stream`
- ✅ Roles on every non-public method, `rolesMatch: All` for high-risk methods
- ✅ `expose_role_names: false` in production
- ✅ Rate limit anonymous endpoints (`scope: Ip`)
- ✅ `max_request_size` set to your maximum acceptable payload (default 1 MB)
- ✅ MCP traffic — if exposed externally, `mcp.apply_rate_limit: true`
