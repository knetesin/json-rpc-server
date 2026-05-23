# 09 — OpenRPC

[OpenRPC](https://spec.open-rpc.org/) is to JSON-RPC what OpenAPI is to REST: a
machine-readable contract that SDK generators, documentation renderers, and
mocking servers can consume.

The bundle emits a valid OpenRPC 1.3.2 document for every registered method:

```bash
bin/console debug:rpc --openrpc \
    --title="Billing API" \
    --api-version="2.4.0" \
    > openrpc.json
```

## What's emitted

```json
{
  "openrpc": "1.3.2",
  "info": {
    "title": "Billing API",
    "version": "2.4.0"
  },
  "methods": [
    {
      "name": "user.get",
      "description": "Look up a user by email.",
      "params": [
        {
          "name": "email",
          "required": true,
          "schema": {
            "type": "string",
            "format": "email"
          }
        },
        {
          "name": "limit",
          "required": false,
          "schema": {
            "type": "integer",
            "minimum": 1,
            "maximum": 100
          }
        }
      ],
      "result": {
        "name": "user.get_result",
        "schema": {
          "type": "object",
          "properties": { ... },
          "additionalProperties": false
        }
      },
      "x-rpc-roles": ["ROLE_USER"],
      "x-rpc-roles-match": "any"
    },
    {
      "name": "user.legacy_get",
      "deprecated": true,
      "x-deprecation-reason": "Use user.get instead.",
      ...
    }
  ]
}
```

## Param flattening

OpenRPC `params` are derived from the same precomputed JSON Schema as MCP
`inputSchema` (built at container compile time). Every **business** key in
that flat schema becomes one OpenRPC parameter entry.

This covers:

- A **single DTO** — ctor properties (`email`, `limit`, …).
- **DTO + scalar siblings** — DTO fields and `#[Rpc\Param]` / auto-promoted
  scalars in one flat list (`street`, `city`, `autoId`).
- **Scalars only** — one entry per parameter.

Server-side params (`Context`, `Request`, `RpcRequest`) never appear — they are
not part of the public contract.

Two DTO parameters that would share a top-level JSON key are rejected at
container build time (same rule as runtime resolution).

## Custom `x-` extensions

The bundle emits some non-standard fields under the `x-` namespace. OpenRPC
clients are required to ignore unknown `x-` keys; bundle-aware tooling (your
own SDK generator, the docs renderer) can read them:

| Field | Type | Source |
|---|---|---|
| `x-deprecation-reason` | string | `#[Rpc\Method(deprecated: '...')]` |
| `x-rpc-roles` | string[] | `#[Rpc\Method(roles: [...])]` |
| `x-rpc-roles-match` | `"any"` \| `"all"` | `#[Rpc\Method(rolesMatch: ...)]` |
| `x-rpc-streaming` | boolean | `#[Rpc\Stream]` |
| `x-rpc-stream-format` | string | `#[Rpc\Stream(format: ...)]` |

## Integration with SDK generators

### TypeScript

[`@open-rpc/generator`](https://github.com/open-rpc/generator) emits typed
client SDKs:

```bash
npx @open-rpc/generator generate \
    -t client-typescript \
    --openrpcDocument=openrpc.json \
    -o ./sdk-ts
```

Result: a typed TS client where `client.user.get({ email })` returns the typed
result.

### Python

```bash
npx @open-rpc/generator generate \
    -t client-python \
    --openrpcDocument=openrpc.json \
    -o ./sdk-py
```

### Documentation

[`@open-rpc/docs-react`](https://github.com/open-rpc/docs-react) or
[OpenRPC playground](https://playground.open-rpc.org/) render the document into
a browsable docs site.

## CI integration

Generate the document during CI to catch unintended API shape changes:

```yaml
# .github/workflows/openrpc.yml
- run: bin/console debug:rpc --openrpc > current-openrpc.json
- run: diff openrpc.json current-openrpc.json   # fail on drift
```

Or commit it under `docs/openrpc.json` and require it to stay in sync.

## Versioning strategies

OpenRPC has `info.version` for the whole document. The bundle's stance: a
JSON-RPC method name is a string namespace — version through the name.

### Strategy A: prefix per version

```php
#[Rpc\Method('v1.user.get')]
#[Rpc\Method('v2.user.get')]
```

Generate one OpenRPC document covering all versions, or filter and emit per
version (requires custom code on top of `OpenRpcDocumentBuilder`).

### Strategy B: separate routes

```yaml
json_rpc_server:
  routes:
    rpc: '/rpc/v2'  # plus a second bundle instance for /rpc/v1
```

Heavy — usually overkill.

### Strategy C: just `info.version`

Stamp the whole bundle as v2.4.0, deprecate methods individually:

```php
#[Rpc\Method('user.legacy_get', deprecated: 'Use user.get instead.')]
```

This is the recommended default.

## Limitations

The schema builder covers PHP types + a curated Validator constraint set.
Things not modeled:

- **List-like arrays** — `list<YourDto>`, `Foo[]`, `array<int, YourDto>` →
  `{type: 'array', items: …}`. Object element types get full nested `items`;
  scalar lists stay `{type: 'array'}` only.
- **String-keyed maps** — `array<string, YourDto>` (and scalar values) →
  `{type: 'object', additionalProperties: …}` so the schema matches JSON object
  maps in client `params`. Union value types map to `additionalProperties.oneOf`.
- Requires PHPDoc on the ctor param or promoted property (`@var` / `@param`);
  `phpstan/phpdoc-parser` + `phpdocumentor/type-resolver` are bundle
  dependencies. Without PHPDoc, bare `array` stays `{type: 'array'}` only.
- Custom Validator constraints — only the standard ones in
  `Symfony\Component\Validator\Constraints\*` are mapped. Custom constraints
  pass through validation but don't appear in the schema.
- Inheritance / interfaces in return types — emitted as `{type: 'object'}`.

For full control over the schema (e.g. to add custom `examples` or
`description` to individual properties), generate the document, post-process
with `jq` / a script, and commit the curated version.
