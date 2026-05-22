# Documentation

Full guide to the JsonRpc Server Bundle. Chapters are written to be read
top-to-bottom on first contact, then referenced individually.

| # | Chapter | What it covers |
|---|---|---|
| 01 | [Getting started](./01-getting-started.md) | Install, register the bundle, first handler, first call |
| 02 | [Methods](./02-methods.md) | `#[Rpc\Method]`, `__invoke`, return types, batch, notifications, deprecation |
| 03 | [Parameters & DTOs](./03-parameters.md) | DTO denormalization, `#[Rpc\Param]`, `RpcParams`, positional vs named, dates |
| 04 | [Security & roles](./04-security.md) | `roles`, `RoleMatch`, security-core integration, redaction |
| 05 | [Caching](./05-caching.md) | `#[Rpc\Cache]`, scopes, pools, tags, `RpcCacheInvalidator` |
| 06 | [Rate limiting](./06-rate-limiting.md) | `#[Rpc\RateLimit]`, four policies, three scopes, `Retry-After` |
| 07 | [Streaming](./07-streaming.md) | `#[Rpc\Stream]`, NDJSON / SSE / JSON-array, mid-stream errors |
| 08 | [MCP](./08-mcp.md) | Tool listing, invoke, formats (incl. TOON), filter, transformer |
| 09 | [OpenRPC](./09-openrpc.md) | Generate the spec, integrate with SDK generators |
| 10 | [Errors](./10-errors.md) | Exception hierarchy, JSON-RPC codes, custom server errors |
| 11 | [Observability](./11-observability.md) | Events, profiler, PSR-3 logging, Sentry, OpenTelemetry |
| 12 | [CLI & maker](./12-cli-and-maker.md) | `debug:rpc`, `rpc:cache:clear`, `make:rpc-method` |
| 13 | [Configuration reference](./13-configuration.md) | Every YAML knob with defaults and recommendations |
| 14 | [Context](./14-context.md) | The `Context` object, request id propagation |

Russian translation is in [`docs/ru/`](../ru/README.md).

## Conventions used here

- `composer require knetesin/json-rpc-server` always refers to the bundle.
- `App\…` is your application namespace.
- Code blocks marked `yaml` are valid Symfony config (place under
  `config/packages/json_rpc_server.yaml`).
- JSON-RPC payload examples use the canonical 2.0 envelope:
  ```json
  { "jsonrpc": "2.0", "method": "…", "params": …, "id": 1 }
  ```

## Reading this online

The docs render as a [GitHub Pages](https://docs.github.com/en/pages) site
served from this `docs/` folder. Local preview:

```bash
gem install bundler jekyll
cd docs && bundle install && bundle exec jekyll serve
```
