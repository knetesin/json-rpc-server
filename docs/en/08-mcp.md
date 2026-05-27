# 08 — MCP

[Model Context Protocol](https://modelcontextprotocol.io/) is Anthropic's
standard for exposing tools and resources to LLM clients (Claude Desktop, your
own LLM-driven agents, etc.). The bundle exposes any RPC method as an MCP tool
without duplicate code paths.

## Two endpoints

| Path | Method | Returns |
|---|---|---|
| `/mcp/tools` | GET | `{"tools": [{name, description, roles, inputSchema, outputSchema?, annotations?}]}` |
| `/mcp/call` | POST | `{"content": [...], "structuredContent": ...}` |

`/mcp/call` body shape:

```json
{ "name": "user.get", "arguments": { "email": "x@y" } }
```

## Enabling MCP

MCP is **off by default** — `/mcp/tools` and `/mcp/call` are not registered
until you turn it on:

```yaml
json_rpc_server:
  mcp:
    enabled: true
```

The Flex recipe ships with `mcp.enabled: false` for the same reason: most
projects don't consume MCP, and a live `/mcp/tools` endpoint is a small
fingerprinting surface for anonymous callers.

## Opting in methods

Once MCP is enabled, only methods with `#[Rpc\Mcp]` are exposed:

```php
#[Rpc\Method('user.get')]
#[Rpc\Mcp(description: 'Look up a user by email.')]
final class GetUser { /* … */ }
```

To expose everything except a few:

```yaml
json_rpc_server:
  mcp:
    expose_all: true
    exclude_prefixes: ['internal.', 'debug.']
    exclude_methods: ['user.delete']
```

To deny everything except a few:

```yaml
json_rpc_server:
  mcp:
    whitelist_methods: ['user.get', 'user.list']
```

Filter priority (first match wins):

1. `exclude_methods` — explicit deny
2. `whitelist_methods` — explicit allow
3. `#[Rpc\Mcp(enabled: false)]` — developer opt-out
4. Method is deprecated (and no explicit `#[Rpc\Mcp]`) → hidden
5. `exclude_prefixes` — bulk deny
6. `expose_all: true` → exposed
7. `#[Rpc\Mcp]` present → exposed
8. Otherwise → hidden

Operator config (`exclude_*`, `whitelist_*`) wins over the developer's
attribute — the deployment owner gets the final say.

## Disabling MCP entirely

`mcp.enabled: false` (the default) removes the routes and services. To turn
it back off after enabling:

```yaml
json_rpc_server:
  mcp:
    enabled: false
```

`JsonSchemaBuilder` stays available either way, so `debug:rpc --openrpc`
still works.

## Input schema

The bundle precomputes a JSON Schema draft-07 fragment for every method's input
at container compile time. `/mcp/tools` serves these directly — no reflection
on each request.

Coverage:

| PHP / Symfony source | JSON Schema |
|---|---|
| `string`, `int`, `float`, `bool`, `array` | `{type: "..."}` |
| `array` + PHPDoc `list<Dto>` / `Dto[]` | `{type: "array", items: {<Dto object schema>}}` — `items` is a schema object, not `[]` |
| `array` + PHPDoc `array<string, Dto>` | `{type: "object", additionalProperties: {<Dto schema>}}` — matches JSON object maps in `params` (not JSON arrays) |
| `?T` | `{type: ["T", "null"]}` |
| Backed enum | `{type, enum: [...]}` |
| Plain enum | `{type: "string", enum: [...]}` |
| `\DateTimeInterface` | depends on `datetime_format` — `string/date-time` or `integer` |
| `Type\Date` | `{type: "string", format: "date"}` |
| `#[Assert\Length(min, max)]` | `minLength`, `maxLength` |
| `#[Assert\Range(min, max)]` | `minimum`, `maximum` |
| `#[Assert\Positive]` | `exclusiveMinimum: 0` |
| `#[Assert\Email]` | `format: email` |
| `#[Assert\Url]` | `format: uri` |
| `#[Assert\Regex]` | `pattern: ...` |
| `#[Assert\Choice]` | `enum: [...]` |

Unknown constraints are skipped (not guessed).

## Output schema

`/mcp/tools` also ships an `outputSchema` next to `inputSchema` when one can be
derived — so MCP clients (and the LLMs behind them) know what shape to expect
from `structuredContent` before they call the tool. The same schema is reused
as the OpenRPC `result.schema` so the two contracts stay in lockstep.

Sources, in priority order:

1. **`#[Rpc\Method(outputSchema: SomeDto::class)]`** — schema-ized via
   the bundle's `JsonSchemaBuilder` (same as `inputSchema` DTO mapping).
2. **`#[Rpc\Method(outputSchema: [...])]`** — a literal JSON Schema array,
   used as-is. Use this when the response is hand-rolled (`array`, mixed
   shapes) and you want clients to see the real keys.
3. **`__invoke()` return type** — auto-detected: scalar → `{type: …}`,
   class/enum → `JsonSchemaBuilder::fromClass(…)`.
4. Otherwise (`array`, `mixed`, `void`, missing) → field is **omitted**.
   Clients then see "no advertised shape" instead of a meaningless
   `{type: array}` placeholder.

```php
#[Rpc\Method('user.get', outputSchema: UserDto::class)]
#[Rpc\Mcp(description: 'Look up a user by id.')]
final class GetUser
{
    /** @return array<string, mixed> */
    public function __invoke(GetUserRequest $req): array { /* ... */ }
}
```

The schema is **advisory only**: it ships as a hint for clients/LLMs and is
**never validated against the actual response**. Drift between the declared
shape and what `__invoke` returns is the developer's responsibility — the
bundle won't silently coerce or reject anything.

## Tool annotations

MCP `tools/list[].annotations` are advisory hints that clients (and the LLMs
behind them) use to decide whether to ask the user before invoking a tool,
throttle retries, etc. They never gate execution — security still belongs to
`roles` / authorization.

```php
#[Rpc\Method('user.delete')]
#[Rpc\Mcp(
    description: 'Delete a user by id.',
    title: 'Delete user',
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
)]
final class DeleteUser { /* ... */ }
```

| Field | Type | Default (MCP spec) | Meaning |
|---|---|---|---|
| `title` | string | — | Human-friendly display label; clients fall back to the method name when absent. |
| `readOnlyHint` | bool | `false` | True if calling never modifies environment state. |
| `destructiveHint` | bool | `true` | True if the tool may delete or destructively mutate state. Only meaningful when `readOnlyHint: false`. |
| `idempotentHint` | bool | `false` | True if repeating the call with identical arguments has no additional effect. |
| `openWorldHint` | bool | `true` | True if the tool can reach external systems (third-party APIs, internet). |

Leaving a field `null` (the bundle default) means "unset" — the bundle either
auto-derives it (see below) or omits it so the client uses the MCP-spec
default.

### Auto-derivation from `#[Rpc\Cache]`

A method that carries `#[Rpc\Cache]` is, by definition, a function of its
arguments and must not mutate observable state during a cache hit — so the
bundle fills:

- `readOnlyHint: true`
- `idempotentHint: true`

whenever neither is set explicitly. Explicit `false` on the attribute always
wins — auto-derivation only fills `null` slots, never overrides developer
intent.

`annotations` is omitted from the tool entry entirely when no field is set
and no auto-derive rule fired, so clients see the spec defaults instead of
an empty object.

## Result formats

How `__invoke` output is rendered into MCP `content`. The default is compact
JSON; pick the right shape for your LLM:

```php
#[Rpc\Mcp(format: McpFormat::Toon)]
```

| Format | Wire | When to use |
|---|---|---|
| `json` (default) | compact JSON | Most cases. |
| `pretty_json` | JSON with indentation | Debugging via Claude Desktop. |
| `markdown` | Markdown table when homogeneous; JSON otherwise | Human-readable summaries. |
| `plain` | String form of scalars; JSON for structures | One-line scalar results. |
| **`toon`** | TOON (token-efficient) | LLM list payloads — 30–50% fewer tokens than JSON. |

Plus `structuredContent` (the normalized object form) is always included
alongside `content` for non-scalar results — MCP spec encourages this so
machine-parsing clients don't have to re-parse the text block.

### Format resolution priority

1. `X-Mcp-Format: toon` header on the request
2. `?format=toon` query parameter
3. `#[Rpc\Mcp(format: McpFormat::Toon)]` attribute
4. `json_rpc_server.mcp.default_format` bundle config
5. Default: `json`

## Customising results: McpResultTransformer

When the JSON-RPC response carries fields the LLM shouldn't see (internal IDs,
debug flags, cache keys), implement `McpResultTransformer` on the handler:

```php
use Knetesin\JsonRpcServerBundle\Mcp\McpResultTransformer;

#[Rpc\Method('user.getById')]
#[Rpc\Mcp]
final class GetById implements McpResultTransformer
{
    public function __invoke(GetByIdRequest $req): UserResponse { /* ... */ }

    public function transformMcpResult(mixed $result): mixed
    {
        // $result is already normalized (array form).
        unset($result['internalDebugFlags'], $result['cacheKey']);
        return $result;
    }
}
```

Runs after `__invoke` and after normalization. The JSON-RPC `/rpc` response is
unaffected — only `/mcp/call` sees the transformed output.

For bulk reshaping across many methods, prefer a custom
`McpResultFormatter` (decorating `DefaultMcpResultFormatter`).

## Description

```php
#[Rpc\Mcp(description: 'Fetch a user profile by email. Returns id, email, name.')]
```

Falls back to `#[Rpc\Method(description: ...)]` when omitted.

## Rate limiting for MCP

`#[Rpc\RateLimit]` does **not** apply to `/mcp/call` by default — MCP traffic
typically comes from a trusted internal agent. Flip on for public MCP:

```yaml
json_rpc_server:
  mcp:
    apply_rate_limit: true
```

## HTTP statuses

| Failure | Status | Body shape |
|---|---|---|
| Parse / invalid envelope | 400 | `{isError: true, error: {...}, content: [text]}` |
| Method not found / not exposed | 404 | same |
| Body too large | 413 | same |
| Auth, rate limit, invalid params, internal error | 200 | same |

200 for handler-level failures is the MCP convention — clients check
`isError: true` in the body, not the HTTP status.

## Connecting Claude Desktop

```json
{
  "mcpServers": {
    "myapp": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-fetch", "https://api.example.com/mcp"]
    }
  }
}
```

Or use any MCP HTTP transport that calls `/mcp/tools` and `/mcp/call`.

## TOON format — when it wins

TOON encodes lists of homogeneous flat objects as a tabular form:

```
users[3]{id,name,email}:
  1,Alice,alice@example.com
  2,Bob,bob@example.com
  3,Carol,carol@example.com
```

vs JSON:

```json
[{"id":1,"name":"Alice","email":"alice@example.com"},
 {"id":2,"name":"Bob","email":"bob@example.com"},
 {"id":3,"name":"Carol","email":"carol@example.com"}]
```

For 100 rows × 6 columns the JSON version is ~2× the tokens. Defaults stay JSON
because most LLMs round-trip JSON more cleanly; toggle to `toon` for read-heavy
listing methods explicitly.
