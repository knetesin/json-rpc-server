# 13 — Configuration reference

Every YAML knob, with defaults and recommendations. Place under
`config/packages/json_rpc_server.yaml`.

## Zero-config defaults

```yaml
json_rpc_server: ~
```

Equivalent to the full tree below. Everything is optional; override only
what you need.

## Full tree

```yaml
json_rpc_server:
    # ---------- security ----------
    security:
        roles_match: any
        expose_role_names: true

    # ---------- request / response shape ----------
    max_request_size: 1048576
    max_json_depth: 32

    json:
        encode_flags: 96            # JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES

    headers:
        deprecation: 'X-Rpc-Deprecated'

    context:
        request_id_header: 'X-Request-Id'

    # ---------- params / DTOs ----------
    params:
        allow_positional_dto: false
        reject_unknown: true

    serializer:
        datetime_format: iso8601
        date_format: 'Y-m-d'
        timezone: ~

    # ---------- handlers in DI ----------
    handlers:
        public: false
        shared: false

    # ---------- routes ----------
    routes:
        rpc:        { path: /rpc,        enabled: true }
        stream:     { path: /rpc/stream, enabled: true }
        mcp_tools:  { path: /mcp/tools,  enabled: true }
        mcp_call:   { path: /mcp/call,   enabled: true }

    # ---------- caching ----------
    cache:
        default_pool: cache.app
        pools: {}
        max_readable_key_length: 200
        key_prefix: rpc.cache
        hash_prefix: rpc

    # ---------- rate limiter ----------
    rate_limiter:
        cache_pool: cache.app

    # ---------- streaming ----------
    stream:
        headers:
            X-Accel-Buffering: no
            Cache-Control: no-cache

    # ---------- profiler ----------
    profiler:
        enabled: true

    # ---------- MCP ----------
    mcp:
        enabled: true
        format_header: 'X-Mcp-Format'
        format_query: 'format'
        default_format: json
        apply_rate_limit: false
        expose_all: false
        exclude_prefixes: []
        exclude_methods: []
        whitelist_methods: []
        schema_max_depth: 6
        markdown:
            max_table_rows: 25
            max_table_cols: 6

    # ---------- observability (all opt-in) ----------
    logging:
        enabled: false
        channel: ~
        level_started: debug
        level_completed: info
        level_failed: warning
        log_params: true
        log_result: false
        slow_threshold_ms: ~

    sentry:
        enabled: false
        breadcrumbs: true
        tag_method: true
        transactions: false
        ignore_exceptions:
            - JsonRpcServer\Exception\InvalidParamsException
            - JsonRpcServer\Exception\InvalidRequestException
            - JsonRpcServer\Exception\MethodNotFoundException
            - JsonRpcServer\Exception\ParseException
            - JsonRpcServer\Exception\AccessDeniedException
            - JsonRpcServer\Exception\RateLimitExceededException

    opentelemetry:
        enabled: false
        tracer_name: 'json-rpc'
        traces: true
        metrics: true
        propagate_traceparent: true
        record_params: false
        record_result: false
        record_max_chars: 2048
        stream:
            record_row_count: true
            span_per_row: false
        ignore_exceptions: [...]  # same default set as Sentry
```

---

## Per-key reference

### `security.roles_match`

Default `any`. Used for `#[Rpc\Method]` when `rolesMatch:` is omitted.

- `any` — at least one of the roles must be granted.
- `all` — every role must be granted.

Per-method override: `#[Rpc\Method(rolesMatch: RoleMatch::All)]`.

### `security.expose_role_names`

Default `true` (dev-friendly). When true, `AccessDeniedException` messages
name the missing role(s): _"One of the following roles is required: ROLE_X,
ROLE_Y"_. Flip to `false` in prod if your role identifiers leak business
structure (`ROLE_BILLING_INTERNAL`) — the client then gets a generic
`Access denied`.

### `max_request_size`

Default `1048576` (1 MiB). Body size limit in bytes for `/rpc`, `/rpc/stream`
and `/mcp/call`. `0` disables.

Per-method override via `#[Rpc\MaxRequestSize(bytes: 65536)]`. When the global
limit is non-zero, the bundle's parser cap is raised at compile time to fit
the largest per-method maximum (so a method declaring a higher limit isn't
rejected before reaching the dispatcher). When the global limit is `0` (i.e.
uncapped), the parser cap stays `0` regardless of per-method values —
otherwise a single method with a small cap would silently cap every other
method at the parser stage.

### `max_json_depth`

Default `32`. Maximum `json_decode` nesting depth for incoming RPC / MCP
payloads. Raise only if your clients legitimately send deep structures.

### `json.encode_flags`

Default `96` (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`). Bitmask of
[json_encode flags](https://www.php.net/manual/en/json.constants.php) for
response bodies. `JSON_THROW_ON_ERROR` is always OR-ed in by the bundle so
encoding failures never silently produce `false`.

Common additions:

| Flag | Value | When |
|---|---|---|
| `JSON_PRETTY_PRINT` | `128` | pretty-print responses (dev) |
| `JSON_PRESERVE_ZERO_FRACTION` | `1024` | force `1.0` over `1` in floats |
| `JSON_HEX_TAG` | `1` | escape `<` `>` for safe HTML embedding |

### `headers.deprecation`

Default `X-Rpc-Deprecated`. Custom HTTP header that carries
human-readable per-method deprecation reasons in the form
`method.name: reason; other.method: …`. The standard `Deprecation: true`
(RFC 9745) is always sent alongside. Override when your platform policy
bans `X-*` prefixes.

### `context.request_id_header`

Default `X-Request-Id`. HTTP header read to populate `Context::$requestId`.
Resolution order (first non-empty wins):

1. Cached value from a prior call in the same request — every item in a
   JSON-RPC batch shares one requestId.
2. The configured header — lets an API gateway pin its correlation id
   end-to-end.
3. Freshly generated `bin2hex(random_bytes(8))`.

Set to an empty string to disable header lookup entirely.

### `params.allow_positional_dto`

Default `false`. Whether handlers with a single DTO parameter accept
positional JSON-RPC params (`"params": [...]`). Forbidden by default because
positional params lock the DTO constructor order into the public API.

Per-method override: `#[Rpc\Method(allowPositionalDto: true)]`.

### `params.reject_unknown`

Default `true`. Whether DTO denormalization rejects unknown fields. The
default catches client typos and stale legacy keys. Override per-method via
`#[Rpc\Method(rejectUnknown: false)]` for backward-compatible endpoints.

### `serializer.datetime_format`

Default `iso8601`. Output format for `DateTimeInterface`.

| Value | Output | Input accepted |
|---|---|---|
| `iso8601` | `2026-05-21T15:00:00+03:00` | ISO, RFC, `"yesterday"`, etc. |
| `timestamp` | unix seconds (int) | int → seconds, string → DateTimeImmutable |
| `timestamp_ms` | unix milliseconds (int) | int → milliseconds, string → DateTimeImmutable |
| any `date()` format | per the format | matching string strict-first, then lenient |

### `serializer.date_format`

Default `Y-m-d`. Output format for `Type\Date` (date without time). On
input strings are tried strictly first, then through `DateTimeImmutable` as
fallback (so `2026-05-21`, `21.05.2026`, `2026/05/21` all parse). Numbers
are accepted as timestamps and truncated to date in the configured timezone.

### `serializer.timezone`

Default `null` (source-value timezone untouched). When set (e.g. `UTC`) the
timezone is applied when normalizing `DateTimeInterface` to a string and
when truncating timestamps to dates. **Setting to `UTC` is strongly
recommended in cross-TZ deployments.**

### `handlers.public`

Default `false`. Whether RPC handler services are public in the container.
Handlers are reached only via the bundle's `ServiceLocator` — `false` keeps
them off the public service API. Flip to `true` only if you need to fetch a
handler directly from `Container::get()`.

### `handlers.shared`

Default `false`. Whether handler services are shared (singleton). Default
`false` is **required** for long-running PHP runtimes (RoadRunner,
FrankenPHP, Swoole) — a shared handler with mutable state would leak data
between requests. Flip to `true` only if your handlers are guaranteed
stateless and you want the per-process instantiation cost saved.

### `routes.{name}.path` / `routes.{name}.enabled`

Each route node accepts either a string (`= path`, enabled) or an object
`{ path: ..., enabled: false }`. `enabled: false` skips the bundled route
entirely — useful if you define your own that points at the bundle
controller.

### `cache.default_pool`

Default `cache.app`. PSR-6 pool service id used when a method's
`#[Rpc\Cache]` doesn't specify `pool:`.

### `cache.pools`

Default `{}`. Named map of additional pools that `#[Rpc\Cache(pool: 'name')]`
can reference. Values are service ids.

```yaml
json_rpc_server:
    cache:
        pools:
            hot: cache.redis_hot
            warm: cache.redis_warm
```

### `cache.max_readable_key_length`

Default `200`. Maximum length of a human-readable cache key. Longer keys
(or those carrying characters PSR-6 reserves) are hashed into
`<hash_prefix>.<sha1>`. Lower for backends with strict key budgets, raise
when keys would benefit from being greppable.

### `cache.key_prefix`

Default `rpc.cache`. Prefix prepended to every cache key the bundle stores.
Change when multiple instances of the bundle share a pool and you need
separate namespaces (e.g. `json_rpc_server.cache.tenant_a` / `json_rpc_server.cache.tenant_b`).

### `cache.hash_prefix`

Default `rpc`. Prefix used when a cache key gets hashed. Final key shape:
`<hash_prefix>.<sha1>`.

### `rate_limiter.cache_pool`

Default `cache.app`. PSR-6 pool used as the Symfony rate-limiter storage.
Point at a dedicated Redis-backed pool for production workloads that need
counters shared across processes.

### `stream.headers`

Map of additional response headers to set on every streamed response. Set
a value to `~` (null) to drop a default. Default disables nginx output
buffering and HTTP caches so each chunk reaches the client immediately:

```yaml
json_rpc_server:
    stream:
        headers:
            X-Accel-Buffering: no
            Cache-Control: no-cache
            # Add your own:
            Access-Control-Allow-Origin: '*'
            # Drop a default:
            X-Accel-Buffering: ~
```

### `profiler.enabled`

Default `true`. Enables the Symfony Web Profiler integration. No-op outside
`kernel.debug`, so safe to leave on in prod.

### `mcp.enabled`

Default `true`. Set `false` to remove MCP services and routes entirely.

### `mcp.format_header` / `mcp.format_query`

Defaults `X-Mcp-Format` and `format`. HTTP header / query-string name read
to pick the MCP result format per request (highest priority in the format
resolution chain). Override when your platform/proxy strips or rewrites
`X-*` headers.

### `mcp.default_format`

Default `json`. Result format used when neither the header / query / per-
method attribute sets one. One of: `json`, `pretty_json`, `markdown`,
`plain`, `toon`.

### `mcp.apply_rate_limit`

Default `false`. Whether to apply `#[Rpc\RateLimit]` when a method is
called via `/mcp/call`. Defaults `false` because MCP traffic typically
comes from a trusted internal agent, not external clients — flip to `true`
if you expose MCP publicly.

### `mcp.expose_all` / `exclude_prefixes` / `exclude_methods` / `whitelist_methods`

The MCP filter chain — see [chapter 08](./08-mcp.md) for the priority order.

### `mcp.schema_max_depth`

Default `6`. Maximum nesting depth `JsonSchemaBuilder` walks into a DTO.
Guards against self-referencing DTOs that would otherwise recurse forever.

### `mcp.markdown.max_table_rows` / `max_table_cols`

Defaults `25` and `6`. Above these the `markdown` MCP format falls back to
JSON instead of rendering an unwieldy table.

### Observability

See [chapter 11](./11-observability.md) for the `logging.*`, `sentry.*`,
and `opentelemetry.*` keys with full context.
