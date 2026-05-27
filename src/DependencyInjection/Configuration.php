<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('json_rpc_server');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $tree->getRootNode();
        $rootNode->children()
            ->append($this->securityNode())
            ->append($this->contextNode())
            ->append($this->headersNode())
            ->integerNode('max_request_size')
                ->defaultValue(1_048_576)
                ->info('Request body size limit in bytes. 0 disables the check.')
            ->end()
            ->integerNode('max_json_depth')
                ->defaultValue(32)
                ->min(1)
                ->info('Max nesting depth for json_decode on incoming RPC / MCP payloads. Bump only if your clients send legitimately deep structures — deeper limits enlarge the parser stack.')
            ->end()
            ->append($this->httpStatusNode())
            ->append($this->serializerNode())
            ->append($this->paramsNode())
            ->append($this->routesNode())
            ->append($this->openRpcMetadataNode())
            ->append($this->handlersNode())
            ->append($this->jsonNode())
            ->append($this->rateLimiterNode())
            ->append($this->streamNode())
            ->append($this->cacheNode())
            ->append($this->profilerNode())
            ->append($this->loggingNode())
            ->append($this->parallelBatchNode())
            ->append($this->openTelemetryNode())
            ->append($this->sentryNode())
            ->append($this->mcpNode())
        ->end();

        return $tree;
    }

    private function securityNode(): NodeDefinition
    {
        $tb = new TreeBuilder('security');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('roles_match')
                    ->values(['any', 'all'])
                    ->defaultValue('any')
                    ->info('Default when #[Rpc\\Method(rolesMatch: ...)] is omitted. "any" = at least one role; "all" = every role.')
                ->end()
                ->booleanNode('expose_role_names')
                    ->defaultTrue()
                    ->info('When true (dev-friendly default), AccessDenied messages name the missing role(s). Flip to false in prod if your role IDs leak business structure ("ROLE_BILLING_INTERNAL") — the client then gets a generic "Access denied".')
                ->end()
                ->arrayNode('default_roles')
                    ->info('Roles applied to every method that does NOT set its own roles in #[Rpc\\Method(roles: ...)]. Empty (default) keeps the historical "no roles = public" behavior. Set to e.g. ["ROLE_USER"] for secure-by-default: only methods listed in public_methods / matching public_prefixes stay anonymous.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('public_prefixes')
                    ->info('Method-name prefixes that stay public even when default_roles is non-empty. Mirrors the MCP exclude_prefixes pattern. Example: ["public.", "health."].')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('public_methods')
                    ->info('Exact method names that stay public even when default_roles is non-empty. Wins over public_prefixes and default_roles. Use for one-off exceptions ("ping", "version").')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('prefix_roles')
                    ->info('Per-prefix default roles applied to methods that do NOT set their own #[Rpc\\Method(roles: ...)]. Map of prefix => list of roles. Longest prefix wins when multiple match. Loses to public_methods / public_prefixes, beats default_roles. Example: {"admin.": ["ROLE_ADMIN"], "internal.": ["ROLE_INTERNAL"]}.')
                    ->useAttributeAsKey('prefix')
                    ->arrayPrototype()
                        ->scalarPrototype()->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $node;
    }

    private function contextNode(): NodeDefinition
    {
        $tb = new TreeBuilder('context');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Settings for `Knetesin\\JsonRpcServerBundle\\Context\\Context` injected into handlers.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('request_id_header')
                    ->defaultValue('X-Request-Id')
                    ->info('HTTP request header read to populate Context::$requestId. The first non-empty value across the bundled lookup chain wins: header → request attribute → freshly generated cryptographic random id. Set to an empty string to disable header lookup entirely (and always generate a fresh id).')
                ->end()
            ->end();

        return $node;
    }

    private function headersNode(): NodeDefinition
    {
        $tb = new TreeBuilder('headers');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Response header names emitted by the bundle. Override when your platform policy bans `X-*` prefixes or you want a different correlation header.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('deprecation')
                    ->defaultValue('X-Rpc-Deprecated')
                    ->info('Custom header that carries the per-method deprecation reason (`method.name: reason; …`). The standard `Deprecation: true` (RFC 9745) is always sent alongside.')
                ->end()
            ->end();

        return $node;
    }

    private function httpStatusNode(): NodeDefinition
    {
        $tb = new TreeBuilder('http_status');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Optional HTTP status mapping for `/rpc`. JSON-RPC errors are always in the body; by default the transport stays 200 except for oversized payloads (413).')
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('When true, `/rpc` mirrors stream-style HTTP codes (400/404/429/500) from `error.code`. Batch responses use the highest status among items. Oversized bodies always return 413 regardless of this flag.')
                ->end()
            ->end();

        return $node;
    }

    private function serializerNode(): NodeDefinition
    {
        $tb = new TreeBuilder('serializer');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Date/time formatting used by the bundled DateNormalizer. Output is strict (uses the configured formats verbatim). Input is lenient — see the per-key info for accepted shapes.')
            ->children()
                ->scalarNode('datetime_format')
                    ->defaultValue('iso8601')
                    ->info('Output format for DateTimeInterface. One of: "iso8601" (2026-05-21T15:00:00+03:00), "timestamp" (unix seconds, integer), "timestamp_ms" (unix milliseconds, integer), or any raw php date() format. On input, numbers are interpreted as seconds when "timestamp" / as milliseconds when "timestamp_ms" / as seconds otherwise; strings go through DateTimeImmutable so ISO, RFC, "yesterday", "2024-01-01 12:00" etc. all work.')
                ->end()
                ->scalarNode('date_format')
                    ->defaultValue('Y-m-d')
                    ->info('Output format for Type\\Date (date without time). On input: strings are first tried against this format strictly, then through DateTimeImmutable as a fallback (so "2026-05-21", "21.05.2026", "2026/05/21" all parse). Numbers are accepted too — interpreted as a timestamp per `datetime_format` and truncated to the date portion in the configured timezone.')
                ->end()
                ->scalarNode('timezone')
                    ->defaultNull()
                    ->info('Timezone applied when normalizing DateTimeInterface to a string and when truncating timestamps to dates (UTC strongly recommended for cross-TZ correctness). Null keeps the source value timezone untouched.')
                ->end()
            ->end();

        return $node;
    }

    private function paramsNode(): NodeDefinition
    {
        $tb = new TreeBuilder('params');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('allow_positional_dto')
                    ->defaultFalse()
                    ->info('Whether handlers with a single DTO parameter accept positional JSON-RPC params (`"params":[…]`). Forbidden by default because positional params lock the DTO constructor order into the public API. Override per-method via #[Rpc\\Method(allowPositionalDto: true)].')
                ->end()
                ->booleanNode('reject_unknown')
                    ->defaultTrue()
                    ->info('Whether DTO denormalization rejects unknown fields. Default true catches client typos and stale fields. Override per-method via #[Rpc\\Method(rejectUnknown: false)] for backward-compatible endpoints that must tolerate extra keys.')
                ->end()
            ->end();

        return $node;
    }

    private function routesNode(): NodeDefinition
    {
        $tb = new TreeBuilder('routes');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Route paths and per-route enabled flag. Set `enabled: false` to skip the bundled route entirely — define your own that targets the controller instead.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('rpc')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()->then(static fn (string $v): array => ['path' => $v])
                    ->end()
                    ->children()
                        ->scalarNode('path')->defaultValue('/rpc')->end()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('stream')
                    ->info('Streaming endpoint (`/rpc/stream`). Off by default — most projects do not have generator-returning handlers. The compiler pass raises an error at build time if any method carries #[Rpc\\Stream] while this is false, so misconfiguration cannot silently turn into a 404.')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()->then(static fn (string $v): array => ['path' => $v])
                    ->end()
                    ->children()
                        ->scalarNode('path')->defaultValue('/rpc/stream')->end()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('mcp_tools')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()->then(static fn (string $v): array => ['path' => $v])
                    ->end()
                    ->children()
                        ->scalarNode('path')->defaultValue('/mcp/tools')->end()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('mcp_call')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()->then(static fn (string $v): array => ['path' => $v])
                    ->end()
                    ->children()
                        ->scalarNode('path')->defaultValue('/mcp/call')->end()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('openrpc')
                    ->info('OpenRPC discovery document. Disabled by default — most projects do not consume it and an anonymous GET leaks the full method list / parameter shapes if the firewall does not also cover this path. Flip enabled: true to expose at the path below.')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifString()->then(static fn (string $v): array => ['path' => $v])
                    ->end()
                    ->children()
                        ->scalarNode('path')->defaultValue('/rpc.openrpc.json')->end()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function openRpcMetadataNode(): NodeDefinition
    {
        $tb = new TreeBuilder('openrpc');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Metadata used when rendering the OpenRPC document at `routes.openrpc.path`. Clients use it for SDK generation and contract validation.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('title')
                    ->defaultValue('JSON-RPC API')
                    ->info('Human-readable name of the API. Shows up in generated docs and client tooling.')
                ->end()
                ->scalarNode('version')
                    ->defaultValue('1.0.0')
                    ->info('API version in semver form. Bump on breaking method changes so generated clients can be regenerated cleanly.')
                ->end()
                ->scalarNode('description')
                    ->defaultNull()
                    ->info('Optional one-paragraph description shown in OpenRPC docs.')
                ->end()
            ->end();

        return $node;
    }

    private function handlersNode(): NodeDefinition
    {
        $tb = new TreeBuilder('handlers');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Visibility and sharing semantics of RPC method handler services in the DI container. Defaults are safe for long-running workers (RoadRunner, FrankenPHP, Swoole): private (handlers are reached only via the bundle\'s ServiceLocator) and non-shared (a fresh instance per dispatch, so mutable state cannot leak between requests).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('public')
                    ->defaultFalse()
                    ->info('Whether RPC handler services are public in the container. Default false — handlers are not part of the public service API and are resolved through MethodRegistry\'s ServiceLocator. Flip to true only if you need to fetch a handler directly from `Container::get()`.')
                ->end()
                ->booleanNode('shared')
                    ->defaultFalse()
                    ->info('Whether RPC handler services are shared (singleton). Default false — every dispatch builds a new handler instance, which is required for long-running PHP runtimes where stateful handlers would leak data between requests. Set true if your handlers are guaranteed stateless and you want the per-process instantiation cost saved.')
                ->end()
            ->end();

        return $node;
    }

    private function jsonNode(): NodeDefinition
    {
        $tb = new TreeBuilder('json');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('JSON encoding configuration for response payloads. Affects /rpc, /rpc/stream and /mcp/call responses. `JSON_THROW_ON_ERROR` is always forced internally — encoding errors must surface, never silently produce `false`.')
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('encode_flags')
                    ->defaultValue(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
                    ->info('Bitmask of json_encode flags. Default keeps Unicode and forward slashes readable on the wire. Common additions: JSON_PRETTY_PRINT (pretty-print responses), JSON_PRESERVE_ZERO_FRACTION, JSON_HEX_TAG. JSON_THROW_ON_ERROR is OR-ed in by the bundle regardless.')
                ->end()
            ->end();

        return $node;
    }

    private function rateLimiterNode(): NodeDefinition
    {
        $tb = new TreeBuilder('rate_limiter');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Symfony rate-limiter configuration used by #[Rpc\\RateLimit].')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('cache_pool')
                    ->defaultValue('cache.app')
                    ->info('PSR-6 cache pool service id used as the rate-limiter storage backend. Defaults to cache.app — point at a dedicated pool (e.g. a Redis-backed one) for production workloads that need shared counters across processes.')
                ->end()
            ->end();

        return $node;
    }

    private function streamNode(): NodeDefinition
    {
        $tb = new TreeBuilder('stream');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Streaming endpoint (`/rpc/stream`) settings.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('headers')
                    ->info('Map of additional response headers to set on every streamed response. Defaults disable nginx output-buffering and HTTP caches so each chunk reaches the client immediately. Set a value to null to drop a default; add new keys to layer your own (e.g. CORS).')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'X-Accel-Buffering' => 'no',
                        'Cache-Control' => 'no-cache',
                    ])
                ->end()
            ->end();

        return $node;
    }

    private function cacheNode(): NodeDefinition
    {
        $tb = new TreeBuilder('cache');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Response caching for methods carrying #[Rpc\\Cache]. The bundle never caches errors and never caches notifications.')
            ->children()
                ->scalarNode('default_pool')
                    ->defaultValue('cache.app')
                    ->info('PSR-6 pool service id used when a method does not specify pool: ...')
                ->end()
                ->arrayNode('pools')
                    ->info('Named map of additional pools that #[Rpc\\Cache(pool: "name")] can reference. Values are service ids.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('max_readable_key_length')
                    ->defaultValue(200)
                    ->min(8)
                    ->info('Maximum length of a human-readable cache key. Longer keys (or those containing characters PSR-6 reserves) are hashed into `<hash_prefix>.<sha1>`. Raise it on backends with generous key budgets (Memcached: 250, Redis: 512 MiB) — lower it on legacy backends with stricter limits.')
                ->end()
                ->scalarNode('key_prefix')
                    ->defaultValue('rpc.cache')
                    ->cannotBeEmpty()
                    ->info('Prefix prepended to every cache key the bundle stores. Change this when multiple instances of the bundle share a cache pool and you need to keep their namespaces separate (e.g. "rpc.cache.tenant_a" / "rpc.cache.tenant_b"). Must only contain `[A-Za-z0-9_.\\-]` to keep keys human-readable — characters PSR-6 reserves trigger the hash fallback.')
                ->end()
                ->scalarNode('hash_prefix')
                    ->defaultValue('rpc')
                    ->cannotBeEmpty()
                    ->info('Prefix used when a cache key gets hashed (too long or carries reserved characters). The full hashed key is `<hash_prefix>.<sha1>`. Keep it short and unique across bundles sharing a pool.')
                ->end()
            ->end();

        return $node;
    }

    private function profilerNode(): NodeDefinition
    {
        $tb = new TreeBuilder('profiler');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Symfony Web Profiler integration (active only when kernel.debug is true and the profiler is enabled).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Record RPC method invocations in the Web Profiler toolbar and panel.')
                ->end()
            ->end();

        return $node;
    }

    private function loggingNode(): NodeDefinition
    {
        $tb = new TreeBuilder('logging');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Built-in PSR-3 logging of every RPC invocation. Listens to MethodInvocation{Started,Completed,Failed} events and emits one log line per outcome. Off by default to avoid duplicate logs when the host project already logs RPC calls itself.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Master switch. When false the subscriber is not registered at all (zero overhead).')
                ->end()
                ->scalarNode('channel')
                    ->defaultNull()
                    ->info('Monolog channel service id (e.g. "monolog.logger.rpc") to write to. Null = the default `logger` service. Requires the channel to be declared in monolog config.')
                ->end()
                ->enumNode('level_started')
                    ->values(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'])
                    ->defaultValue('debug')
                    ->info('PSR-3 log level for `rpc.call.started`. Debug by default — most projects only care about completions.')
                ->end()
                ->enumNode('level_completed')
                    ->values(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'])
                    ->defaultValue('info')
                    ->info('PSR-3 log level for successful `rpc.call.completed` events.')
                ->end()
                ->enumNode('level_failed')
                    ->values(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'])
                    ->defaultValue('warning')
                    ->info('PSR-3 log level for `rpc.call.failed`. Warning by default — Sentry / log alerts typically gate on >=warning.')
                ->end()
                ->booleanNode('log_params')
                    ->defaultTrue()
                    ->info('Whether to include the request params in the log context. Disable in PII-sensitive environments (or write your own subscriber that redacts).')
                ->end()
                ->booleanNode('log_result')
                    ->defaultFalse()
                    ->info('Whether to include the (possibly large) result in the `completed` log context. Off by default — DTO results bloat log lines.')
                ->end()
                ->integerNode('slow_threshold_ms')
                    ->defaultNull()
                    ->min(0)
                    ->info('When set, completed calls slower than this threshold are escalated to the `level_failed` level so they show up in alerting. Null disables the escalation.')
                ->end()
            ->end();

        return $node;
    }

    private function parallelBatchNode(): NodeDefinition
    {
        $tb = new TreeBuilder('parallel_batch');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Optional loopback fan-out for JSON-RPC batches. When enabled, the server sends each batch item as a separate HTTP request back to itself so PHP-FPM / RoadRunner workers can process them concurrently. **Has real operational risk** — read the documentation before enabling. Default: off. Multiple safety layers (system-wide budget, recursion guard, timeout fallback) gate the feature.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Master switch. Off by default. Turn on only after sizing the worker pool — see budget below.')
                ->end()
                ->scalarNode('self_url')
                    ->defaultNull()
                    ->info('URL the fan-out POSTs sub-calls to. Null derives it from the incoming request scheme+host. Set explicitly to point at a separate worker pool (e.g. http://127.0.0.1/internal/rpc-fanout served by a dedicated FPM pool) — strongly recommended for production.')
                ->end()
                ->integerNode('min_batch_size')
                    ->defaultValue(2)
                    ->min(2)
                    ->info('Batches smaller than this go sequential — the network overhead of fan-out only pays off for 2+ items.')
                ->end()
                ->integerNode('max_concurrency')
                    ->defaultValue(3)
                    ->min(1)
                    ->info('Per-batch concurrency cap. A batch of 10 with max_concurrency=3 runs as 4 waves of (3,3,3,1). Lower = safer for the pool, higher = faster batches.')
                ->end()
                ->integerNode('budget')
                    ->defaultValue(10)
                    ->min(0)
                    ->info('System-wide cap on in-flight fan-out sub-calls across ALL concurrent batches. When exceeded, new batches transparently fall back to sequential. Set this to `(pm.max_children - reserved-for-normal-traffic)`. 0 disables the global budget (NOT recommended in production).')
                ->end()
                ->scalarNode('budget_store')
                    ->defaultValue('apcu')
                    ->info('Backing store for the system-wide budget counter. "apcu" uses PHP\'s APCu extension (lock-free, shared across the FPM master\'s children). "null" disables the global cap entirely. Custom: service id implementing Knetesin\\JsonRpcServerBundle\\Batch\\BudgetTrackerInterface.')
                ->end()
                ->integerNode('max_depth')
                    ->defaultValue(1)
                    ->min(0)
                    ->info('Maximum fan-out depth. Default 1 means: incoming batches fan out, but their sub-calls do not fan out again. Prevents recursion blow-up. 0 effectively disables fan-out.')
                ->end()
                ->floatNode('connect_timeout')
                    ->defaultValue(0.5)
                    ->info('Seconds to wait for the loopback TCP connect. Short — if the pool is busy, sub-calls bounce off quickly and the parent falls back.')
                ->end()
                ->floatNode('timeout')
                    ->defaultValue(10.0)
                    ->info('Total per-sub-call timeout in seconds. Match your slowest handler\'s SLA.')
                ->end()
                ->arrayNode('forward_headers')
                    ->info('Incoming request headers that should be re-sent on each sub-call. Authorization keeps auth in scope. X-Request-Id keeps the correlation id stable across the trace tree.')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'Authorization',
                        'Cookie',
                        'X-Request-Id',
                        'X-Forwarded-For',
                        'X-Forwarded-Proto',
                        'traceparent',
                        'tracestate',
                    ])
                ->end()
            ->end();

        return $node;
    }

    private function openTelemetryNode(): NodeDefinition
    {
        $tb = new TreeBuilder('opentelemetry');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Optional OpenTelemetry bridge. Emits OTel-spec traces, metrics, and (optionally) propagates W3C trace-context headers. Vendor-neutral — works with any OTel-compatible backend (Jaeger, Datadog, Grafana Tempo, Honeycomb, AWS X-Ray, …). Requires open-telemetry/sdk to be installed; the subscriber is not registered otherwise.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Master switch. When false the subscriber is not registered.')
                ->end()
                ->scalarNode('tracer_name')
                    ->defaultValue('json-rpc')
                    ->info('Name used when fetching the Tracer / Meter from the global provider. Shows up as `instrumentation_library.name` in exported telemetry — pick something distinctive if you have multiple instrumented libraries.')
                ->end()
                ->booleanNode('traces')
                    ->defaultTrue()
                    ->info('Open a SERVER-kind span per RPC call with `rpc.system=jsonrpc` and OTel semantic-conventions attributes.')
                ->end()
                ->booleanNode('metrics')
                    ->defaultTrue()
                    ->info('Record `rpc.server.duration` histogram (ms) and `rpc.server.requests` counter, labelled by `rpc.method` and `outcome` (ok / error). Compatible with Prometheus-style scraping when paired with an OTel collector or PHP exporter.')
                ->end()
                ->booleanNode('propagate_traceparent')
                    ->defaultTrue()
                    ->info('Read W3C Trace Context headers (`traceparent` / `tracestate`) from the incoming HTTP request and attach the RPC span as a child. Lets you stitch the call into a distributed trace started by an upstream service.')
                ->end()
                ->booleanNode('record_params')
                    ->defaultFalse()
                    ->info('Attach the request params as a span attribute (`rpc.jsonrpc.params`, JSON-encoded). Off by default — params may carry PII.')
                ->end()
                ->booleanNode('record_result')
                    ->defaultFalse()
                    ->info('Attach the (truncated) result as a span attribute (`rpc.jsonrpc.result`). Off by default — DTOs are usually too verbose for trace attributes.')
                ->end()
                ->integerNode('record_max_chars')
                    ->defaultValue(2048)
                    ->min(0)
                    ->info('Maximum characters of `record_params` / `record_result` to capture; longer values are truncated with a "…" suffix. Keeps trace exports small.')
                ->end()
                ->arrayNode('stream')
                    ->addDefaultsIfNotSet()
                    ->info('Behaviour for streaming methods (`/rpc/stream`).')
                    ->children()
                        ->booleanNode('record_row_count')
                            ->defaultTrue()
                            ->info('Set `rpc.stream.row_count` attribute on the stream span after iteration completes. Cheap; safe to keep on.')
                        ->end()
                        ->booleanNode('span_per_row')
                            ->defaultFalse()
                            ->info('Open a CLIENT-kind child span for each row the iterator yields. Expensive (one span per row blows up cardinality and storage) — turn on only for short streams when debugging row-level latency.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ignore_exceptions')
                    ->info('Fully-qualified exception classes whose failures should NOT mark the span as ERROR or bump the error counter. Defaults to the standard client-side RPC errors so they do not pollute SLO dashboards.')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'Knetesin\\JsonRpcServerBundle\\Exception\\InvalidParamsException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\InvalidRequestException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\MethodNotFoundException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\ParseException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\AccessDeniedException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\RateLimitExceededException',
                    ])
                ->end()
            ->end();

        return $node;
    }

    private function sentryNode(): NodeDefinition
    {
        $tb = new TreeBuilder('sentry');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->info('Optional Sentry integration. Adds breadcrumbs, a `rpc.method` tag and (optionally) child spans for the active Sentry transaction. Requires sentry/sentry-symfony to be installed — the subscriber is not registered otherwise, regardless of the flag.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Master switch. When false the subscriber is not registered.')
                ->end()
                ->booleanNode('breadcrumbs')
                    ->defaultTrue()
                    ->info('Emit a breadcrumb per `started`/`completed`/`failed` event. Visible in every Sentry issue as the recent activity timeline.')
                ->end()
                ->booleanNode('tag_method')
                    ->defaultTrue()
                    ->info('Set `rpc.method` as a Sentry tag while a call is in flight, so issues can be filtered/grouped by method.')
                ->end()
                ->booleanNode('transactions')
                    ->defaultFalse()
                    ->info('Wrap each RPC call in a child span of the active Sentry transaction. Off by default — turn on only when Sentry Performance Monitoring is enabled in your project.')
                ->end()
                ->arrayNode('ignore_exceptions')
                    ->info('Fully-qualified exception classes whose `failed` events should NOT produce breadcrumbs / spans (client-side errors that pollute Sentry: InvalidParams, AccessDenied, MethodNotFound, RateLimitExceeded — useful defaults below). Children-class matches too.')
                    ->scalarPrototype()->end()
                    ->defaultValue([
                        'Knetesin\\JsonRpcServerBundle\\Exception\\InvalidParamsException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\InvalidRequestException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\MethodNotFoundException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\ParseException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\AccessDeniedException',
                        'Knetesin\\JsonRpcServerBundle\\Exception\\RateLimitExceededException',
                    ])
                ->end()
            ->end();

        return $node;
    }

    private function mcpNode(): NodeDefinition
    {
        $tb = new TreeBuilder('mcp');
        /** @var ArrayNodeDefinition $node */
        $node = $tb->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Off by default — MCP is an opt-in integration for AI agents. When true, registers /mcp/tools + /mcp/call routes and the MCP services. Leave false unless you actually consume the bundle from an MCP client (Claude Desktop, internal agent, …). The per-method #[Rpc\\Mcp] attribute and expose_all/exclude_* still gate which methods are listed.')
                ->end()
                ->scalarNode('format_header')
                    ->defaultValue('X-Mcp-Format')
                    ->cannotBeEmpty()
                    ->info('HTTP request header name read to pick the MCP result format (highest priority in the resolution chain). Override when your platform/proxy strips or rewrites `X-*` headers.')
                ->end()
                ->scalarNode('format_query')
                    ->defaultValue('format')
                    ->cannotBeEmpty()
                    ->info('Query-string parameter name read for the MCP result format (used when the header is absent). Typically left as `format`; rename if it collides with a tool argument the client sends.')
                ->end()
                ->enumNode('default_format')
                    ->values(['json', 'pretty_json', 'markdown', 'plain', 'toon'])
                    ->defaultValue('json')
                    ->info('Result format used when neither the X-Mcp-Format header, the ?format query parameter, nor a per-method #[Rpc\\Mcp(format: ...)] sets one. Pick "toon" for LLM consumers — much fewer tokens on list payloads.')
                ->end()
                ->booleanNode('apply_rate_limit')
                    ->defaultFalse()
                    ->info('Whether to apply #[Rpc\\RateLimit] when a method is called via /mcp/call. Defaults to false because MCP traffic typically comes from a trusted internal agent, not external clients — flip to true if you expose MCP publicly.')
                ->end()
                ->booleanNode('expose_all')
                    ->defaultFalse()
                    ->info('When true, every RPC method is exposed via MCP unless filtered out below. When false (default), only methods carrying #[Rpc\\Mcp] are exposed.')
                ->end()
                ->arrayNode('exclude_prefixes')
                    ->info('Hide any method whose name starts with one of these prefixes.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('exclude_methods')
                    ->info('Exact method names to hide. Wins over whitelist_methods and the attribute.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('whitelist_methods')
                    ->info('Method names that are always exposed, overriding exclude_prefixes and the attribute check.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('schema_max_depth')
                    ->defaultValue(6)
                    ->min(1)
                    ->info('Maximum nesting depth JsonSchemaBuilder walks into a DTO. Guards against self-referencing DTOs that would otherwise recurse forever. Bump only if you have legitimately deep nested DTOs you want fully described.')
                ->end()
                ->arrayNode('markdown')
                    ->addDefaultsIfNotSet()
                    ->info('Thresholds for the `markdown` MCP result format: above these the formatter falls back to JSON instead of rendering an unwieldy table.')
                    ->children()
                        ->integerNode('max_table_rows')
                            ->defaultValue(25)
                            ->min(1)
                        ->end()
                        ->integerNode('max_table_cols')
                            ->defaultValue(6)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}
