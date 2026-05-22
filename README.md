# JsonRpc Server Bundle

[![CI](https://github.com/knetesin/json-rpc-server/actions/workflows/ci.yml/badge.svg)](https://github.com/knetesin/json-rpc-server/actions)
[![codecov](https://codecov.io/gh/knetesin/json-rpc-server/graph/badge.svg)](https://codecov.io/gh/knetesin/json-rpc-server)
[![Latest Version](https://img.shields.io/packagist/v/knetesin/json-rpc-server.svg?style=flat-square)](https://packagist.org/packages/knetesin/json-rpc-server)
[![Total Downloads](https://img.shields.io/packagist/dt/knetesin/json-rpc-server.svg?style=flat-square)](https://packagist.org/packages/knetesin/json-rpc-server)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

A modern JSON-RPC 2.0 server for Symfony — built around attributes, DTOs, and
the rest of the framework you already use. Speaks JSON-RPC for your own
clients, MCP for AI agents, and NDJSON / SSE when you need to stream.

```php
#[Rpc\Method('user.update', roles: ['ROLE_USER'])]
final class UpdateUser
{
    public function __construct(private readonly UserApi $users) {}

    public function __invoke(UpdateUserRequest $req, Context $ctx): UserResponse
    {
        return UserResponse::fromArray(
            $this->users->update($req->id, $req->toArray(), $ctx->user->getId()),
        );
    }
}
```

That's a full handler. No routing, no controllers, no manual validation, no
container wiring — the bundle does the boring parts.

---

## Documentation

Full guide in [`docs/en/`](docs/en/README.md) ([RU](docs/ru/README.md)):

| Chapter | Covers |
|---|---|
| [Getting started](docs/en/01-getting-started.md) | Install, first handler, first call |
| [Methods](docs/en/02-methods.md) | `#[Rpc\Method]`, batch, notifications, deprecation |
| [Parameters & DTOs](docs/en/03-parameters.md) | DTO denormalization, `#[Rpc\Param]`, dates |
| [Security & roles](docs/en/04-security.md) | `roles`, `RoleMatch`, security-core integration |
| [Caching](docs/en/05-caching.md) | `#[Rpc\Cache]`, scopes, pools, tags, invalidator |
| [Rate limiting](docs/en/06-rate-limiting.md) | Four policies, three scopes |
| [Streaming](docs/en/07-streaming.md) | NDJSON / SSE / JSON-array, error frames |
| [MCP](docs/en/08-mcp.md) | Tool listing, invoke, formats, transformer |
| [OpenRPC](docs/en/09-openrpc.md) | Generate the spec |
| [Errors](docs/en/10-errors.md) | Exception hierarchy, custom server errors |
| [Observability](docs/en/11-observability.md) | Events, profiler, logging, Sentry, OpenTelemetry |
| [CLI & maker](docs/en/12-cli-and-maker.md) | `debug:rpc`, `rpc:cache:clear`, `make:rpc-method` |
| [Configuration reference](docs/en/13-configuration.md) | Every YAML knob |
| [Context](docs/en/14-context.md) | The `Context` object, request id |

The same chapters are also served at
[knetesin.github.io/json-rpc-server](https://knetesin.github.io/json-rpc-server/)
once GitHub Pages is enabled on the `docs/` folder.

---

## Table of contents

- [Why this bundle](#why-this-bundle)
- [Requirements](#requirements)
- [Install](#install)
- [Five-minute tour](#five-minute-tour)
- [Feature highlights](#feature-highlights)
- [Configuration](#configuration)
- [Versioning](#versioning)
- [Contributing](#contributing)
- [License](#license)

---

## Why this bundle

- **Real Symfony, not glued on.** Methods are services, DTOs go through
  Symfony Serializer, validation through Symfony Validator, authorisation
  through Symfony Security. No parallel universe to maintain.
- **Attribute-driven.** `#[Rpc\Method]`, `#[Rpc\Cache]`, `#[Rpc\RateLimit]`,
  `#[Rpc\Stream]`, `#[Rpc\Mcp]`. One place to read, one place to grep.
- **Compile-time discovery.** Every method is registered in a container
  compiler pass — zero reflection in the hot path, zero boot tax.
- **First-class MCP.** Expose handlers as MCP tools with auto-generated JSON
  Schemas. Five rendering formats (`json`, `pretty_json`, `markdown`, `plain`,
  `toon`) so the same tool can answer LLM agents and machine clients with
  shapes each prefers.
- **Streaming on its own endpoint.** NDJSON, Server-Sent Events, JSON-array.
  Spec-compliant `/rpc` stays unchanged; `/rpc/stream` is the deliberate
  extension.
- **Built-in observability.** Drop a flag in YAML and get PSR-3 logs,
  Symfony Web Profiler entries, Sentry breadcrumbs, or vendor-neutral
  OpenTelemetry traces + metrics + W3C trace-context propagation.
- **Safe defaults.** Handlers are non-shared (no state leak under RoadRunner /
  FrankenPHP / Swoole). DTOs reject unknown fields. Cache invalidation by tag.
  Per-method body-size limits. Deprecation headers.

---

## Requirements

- PHP **8.3+** (typed class constants are used throughout)
- Symfony **7.x** or **8.x**
- `ext-json`

Optional packages (everything degrades gracefully when absent — the container
build fails loudly only if you reference a feature whose package is missing):

| Package | Enables |
|---|---|
| `symfony/security-bundle` | role checks, authenticated `Context::$user`, user-scoped rate limit / cache |
| `symfony/cache` | tag-aware cache invalidation (`RpcCacheInvalidator::purgeMethod / purgeTags`) |
| `symfony/rate-limiter` | `#[Rpc\RateLimit]` |
| `symfony/maker-bundle` | `bin/console make:rpc-method` scaffolder |
| `symfony/web-profiler-bundle` | RPC panel in the Symfony Web Profiler |
| `sentry/sentry-symfony` | Sentry breadcrumbs / tags / spans |
| `open-telemetry/sdk` | OpenTelemetry traces / metrics / propagation |

---

## Install

```bash
composer require knetesin/json-rpc-server
```

With **Symfony Flex** the bundle auto-registers itself, drops a default
`config/packages/json_rpc_server.yaml`, and wires the routes file. Without Flex:

```php
// config/bundles.php
return [
    // ...
    JsonRpcServer\JsonRpcServerBundle::class => ['all' => true],
];
```

```yaml
# config/routes/json_rpc_server.yaml
json_rpc_server:
    resource: '@JsonRpcServerBundle/Resources/config/routes.php'
    type: php
```

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server: ~
```

That's it. Default routes:

| Route | Path | Method |
|---|---|---|
| `rpc` | `/rpc` | POST |
| `rpc_stream` | `/rpc/stream` | POST |
| `rpc_mcp_tools` | `/mcp/tools` | GET |
| `rpc_mcp_call` | `/mcp/call` | POST |

All paths configurable; any route disable-able via
`json_rpc_server.routes.{name}.enabled: false`.

---

## Five-minute tour

### A handler

```php
// src/Rpc/Add.php
use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('math.add', description: 'Add two integers.')]
final class Add
{
    public function __invoke(int $a, int $b): array
    {
        return ['sum' => $a + $b];
    }
}
```

```bash
curl -X POST http://localhost/rpc \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'
```

```json
{"jsonrpc":"2.0","result":{"sum":5},"id":1}
```

### A DTO

```php
final class UpdateUserRequest
{
    public function __construct(
        #[Assert\Uuid]                       public string $id,
        #[Assert\Length(min: 2, max: 120)]   public string $name,
        #[Assert\Email]                      public ?string $email = null,
        public ?Date $birthday = null,
    ) {}
}

#[Rpc\Method('user.update', roles: ['ROLE_USER'])]
final class UpdateUser
{
    public function __invoke(UpdateUserRequest $req, Context $ctx): UserResponse { /* … */ }
}
```

Invalid input surfaces as `-32602 Invalid params` with per-field violation
paths in `error.data`. No try/catch in your handler.

### Inspecting

```bash
bin/console debug:rpc
bin/console debug:rpc user.update --schema   # JSON Schema of the DTO
```

### Scaffolding (with `symfony/maker-bundle`)

```bash
bin/console make:rpc-method UserGetByEmail \
    --method=user.getByEmail --with-dto --with-test
```

---

## Feature highlights

### DTOs and validation

DTOs are plain PHP classes. The bundle denormalizes incoming JSON via Symfony
Serializer (enums, dates, nested VOs, value objects with constructors —
everything), validates via Symfony Validator, and surfaces violations with
their field paths. `#[Rpc\Param]` is available for handlers that prefer
scalar parameters over a DTO.

### Roles

```php
#[Rpc\Method('admin.users.delete', roles: ['ROLE_ADMIN', 'ROLE_USER_ADMIN'])]
#[Rpc\Method('billing.invoice.void', roles: [...], rolesMatch: RoleMatch::All)]
```

`any` (default) requires one of the roles; `all` requires every role. Public
methods omit `roles`.

### Caching

```php
#[Rpc\Method('feed.list')]
#[Rpc\Cache(ttl: 60, scope: UserScope::class, tags: ['feed'])]
```

Cache key composed from method + scope contributor (user / IP / your own) +
hashed params. Notifications never cached. Tag-aware invalidation via
`RpcCacheInvalidator` when `symfony/cache` is installed.

### Rate limiting

```php
#[Rpc\Method('email.send')]
#[Rpc\RateLimit(limit: 10, intervalSec: 60, scope: RateLimitScope::User)]
```

Four policies (`FixedWindow`, `SlidingWindow`, `TokenBucket`, `NoLimit`),
three scopes (`User`, `Ip`, `GlobalScope`). Excess calls throw
`RateLimitExceededException` (code `-32003`) with `retryAfter` in `data`.

### Streaming

```php
#[Rpc\Method('export.users')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
final class ExportUsers
{
    public function __invoke(ExportRequest $req): \Generator
    {
        foreach ($this->repo->iterate($req->filters) as $row) {
            yield $row;
        }
    }
}
```

POST the same JSON-RPC envelope to `/rpc/stream`. Three formats: `Ndjson`,
`Sse`, `JsonArray`. Mid-stream errors emit an inline error frame in the
active format instead of breaking the HTTP response.

### MCP — for LLM agents

Two ways to expose methods as Model Context Protocol tools:

1. **Opt-in per method**: `#[Rpc\Mcp(description: '…')]`
2. **Opt-out by prefix**: `json_rpc_server.mcp.expose_all: true` + `exclude_prefixes: ['auth.']`

`GET /mcp/tools` lists tools with auto-generated JSON Schemas built from the
DTO constructor and a curated set of Symfony Validator constraints
(`NotBlank`, `Length`, `Range`, `Positive`, `Choice`, `Email`, `Url`,
`Regex`). `POST /mcp/call` invokes them.

Five rendering formats — chosen per-request via header / query / attribute:

| Format | Output |
|---|---|
| `json` (default) | compact JSON, one line — smallest payload |
| `pretty_json` | indented JSON — chat UI |
| `markdown` | tables for lists, text for scalars, JSON for the rest |
| `plain` | scalars unquoted, objects pretty JSON |
| `toon` | TOON — indentation-based, token-efficient for LLM consumers |

### Typed exceptions

```php
final class QuotaExceededException extends RpcException
{
    public function __construct(int $used, int $limit) {
        parent::__construct(sprintf('Quota exceeded: %d/%d', $used, $limit));
    }
    public function rpcCode(): int { return -32010; }
    public function rpcData(): mixed { return ['retryAfter' => 60]; }
}

throw new QuotaExceededException($used, $limit);
```

Bundle-provided exceptions cover `-32700` Parse, `-32600` InvalidRequest,
`-32601` MethodNotFound, `-32602` InvalidParams, `-32603` Internal,
`-32001` AccessDenied, `-32002` NotFound, `-32003` RateLimitExceeded.

### Context

```php
public function __invoke(MyRequest $req, Context $ctx): MyResponse
{
    // $ctx->methodName  — 'user.update'
    // $ctx->requestId   — X-Request-Id header or auto-generated
    // $ctx->user        — Symfony security user (?UserInterface)
    // $ctx->roles       — list<string>
}
```

No `Security::getUser()` calls everywhere; the dispatcher hands you Context
when you ask for it.

### Observability — pick your stack, all opt-in

| Stack | Switch |
|---|---|
| **PSR-3 logging** | `json_rpc_server.logging.enabled: true` |
| **Symfony Web Profiler** | auto-active in `kernel.debug` |
| **Sentry** (breadcrumbs / tag / spans) | `json_rpc_server.sentry.enabled: true` |
| **OpenTelemetry** (traces / metrics / propagation) | `json_rpc_server.opentelemetry.enabled: true` |

All four read the same three PSR-14 events the dispatcher fires
(`MethodInvocationStarted/Completed/Failed`), plus the streaming events.
Wire your own listener for anything custom.

### OpenRPC document

`OpenRpcDocumentBuilder` generates an [OpenRPC](https://open-rpc.org/) spec
of every registered method — feed it to SDK generators / Postman / docs sites.

### Deprecation

`#[Rpc\Method(deprecated: 'use user.v2.update instead')]` — every call is
logged with the reason, and the response carries `Deprecation: true` (RFC
9745) plus the human-readable hint in the configurable
`X-Rpc-Deprecated` header. Deprecated methods auto-hidden from MCP.

---

## Configuration

Every knob, all defaults shown. Place under
`config/packages/json_rpc_server.yaml`.

```yaml
json_rpc_server:
    # ---------- security ----------
    security:
        roles_match: any            # default for methods without rolesMatch
        expose_role_names: true     # AccessDenied messages name missing role(s)

    # ---------- request / response shape ----------
    max_request_size: 1048576       # bytes; 0 disables. 1 MiB default
    max_json_depth: 32              # json_decode nesting limit

    json:
        encode_flags: 96            # bitmask of json_encode flags for responses
                                    # default 96 = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                    # JSON_THROW_ON_ERROR is always OR-ed in by the bundle

    headers:
        deprecation: 'X-Rpc-Deprecated'  # custom header carrying the deprecation reason

    context:
        request_id_header: 'X-Request-Id'  # set '' to disable header lookup

    # ---------- params / DTOs ----------
    params:
        allow_positional_dto: false  # accept `params: [...]` for single-DTO handlers
        reject_unknown: true         # DTO denormalization fails on unknown fields

    serializer:
        datetime_format: iso8601    # iso8601 | timestamp | timestamp_ms | any date() format
        date_format: 'Y-m-d'        # Type\Date wire format
        timezone: ~                 # null = keep source value timezone

    # ---------- handlers in DI ----------
    handlers:
        public: false               # whether handler services are public
        shared: false               # safe for long-running runtimes; flip when stateless

    # ---------- routes (per-route enabled flag) ----------
    routes:
        rpc:        { path: /rpc,        enabled: true }
        stream:     { path: /rpc/stream, enabled: true }
        mcp_tools:  { path: /mcp/tools,  enabled: true }
        mcp_call:   { path: /mcp/call,   enabled: true }

    # ---------- caching ----------
    cache:
        default_pool: cache.app
        pools: {}                   # { name: service.id } — referenced by #[Rpc\Cache(pool: 'name')]
        max_readable_key_length: 200
        key_prefix: rpc.cache
        hash_prefix: rpc

    # ---------- rate limiter ----------
    rate_limiter:
        cache_pool: cache.app       # PSR-6 pool used as storage

    # ---------- streaming ----------
    stream:
        headers:                    # set null to remove a default header
            X-Accel-Buffering: no
            Cache-Control: no-cache

    # ---------- profiler ----------
    profiler:
        enabled: true               # no-op outside kernel.debug

    # ---------- MCP ----------
    mcp:
        enabled: true
        format_header: 'X-Mcp-Format'
        format_query: 'format'
        default_format: json        # json | pretty_json | markdown | plain | toon
        apply_rate_limit: false     # apply #[Rpc\RateLimit] on /mcp/call
        expose_all: false           # every RPC method becomes an MCP tool unless excluded
        exclude_prefixes: []
        exclude_methods: []
        whitelist_methods: []
        schema_max_depth: 6         # JsonSchemaBuilder recursion guard
        markdown:
            max_table_rows: 25      # above this `markdown` falls back to JSON
            max_table_cols: 6

    # ---------- observability (all opt-in) ----------
    logging:
        enabled: false
        channel: ~                  # e.g. monolog.logger.rpc
        level_started: debug
        level_completed: info
        level_failed: warning
        log_params: true
        log_result: false
        slow_threshold_ms: ~        # escalates slow calls to level_failed

    sentry:
        enabled: false
        breadcrumbs: true
        tag_method: true
        transactions: false
        ignore_exceptions: [...]    # default: standard client-side exceptions

    opentelemetry:
        enabled: false
        tracer_name: json-rpc
        traces: true
        metrics: true
        propagate_traceparent: true
        record_params: false
        record_result: false
        record_max_chars: 2048
        stream:
            record_row_count: true
            span_per_row: false
        ignore_exceptions: [...]    # default: standard client-side exceptions
```

Full reference with every knob's rationale: [`docs/en/13-configuration.md`](docs/en/13-configuration.md).

---

## Versioning

Semantic Versioning. Anything outside the documented public API
(`JsonRpcServer\Attribute\*`, `JsonRpcServer\Context\*`,
`JsonRpcServer\Exception\*`, `JsonRpcServer\Type\*`, event classes,
configuration tree) is internal and may change in patch releases.

---

## Contributing

```bash
git clone https://github.com/knetesin/json-rpc-server
cd json-rpc-server
composer install
composer check    # cs-check + phpstan + test
```

Pull requests welcome. Discussion / questions:
[GitHub Discussions](https://github.com/knetesin/json-rpc-server/discussions).
Bugs: [issues](https://github.com/knetesin/json-rpc-server/issues).

For larger features, please open a discussion first — the bundle aims to stay
small at the core and push everything else to opt-in subscribers.

---

## License

[MIT](LICENSE). © Contributors of
[`knetesin/json-rpc-server`](https://github.com/knetesin/json-rpc-server).
