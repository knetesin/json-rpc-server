# 08 — MCP

[Model Context Protocol](https://modelcontextprotocol.io/) is Anthropic's
standard for exposing tools and resources to LLM clients (Claude Desktop, your
own LLM-driven agents, etc.). The bundle exposes any RPC method as an MCP tool
without duplicate code paths.

## Two endpoints

| Path | Method | Returns |
|---|---|---|
| `/mcp/tools` | GET | `{"tools": [{name, description, roles, inputSchema}]}` |
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
