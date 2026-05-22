# 11 — Observability

The bundle ships four observability stacks out of the box — all opt-in, all
reading the same PSR-14 events the dispatcher fires. You can mix and match;
nothing competes with anything.

| Stack | Switch | Best for |
|---|---|---|
| [PSR-14 events](#psr-14-events) | always on | writing your own listener |
| [Symfony Web Profiler](#symfony-web-profiler) | `json_rpc_server.profiler.enabled` (debug only) | local development |
| [PSR-3 logging](#psr-3-logging) | `json_rpc_server.logging.enabled` | structured app logs |
| [Sentry](#sentry) | `json_rpc_server.sentry.enabled` | issue tracking with breadcrumbs / tags / spans |
| [OpenTelemetry](#opentelemetry) | `json_rpc_server.opentelemetry.enabled` | vendor-neutral traces / metrics / propagation |

---

## PSR-14 events

The dispatcher fires three events for every RPC call:

```php
namespace JsonRpcServer\Event;

final readonly class MethodInvocationStartedEvent {
    public MethodMetadata $method;
    public RpcParams      $params;
}

final readonly class MethodInvocationCompletedEvent {
    public MethodMetadata $method;
    public RpcParams      $params;
    public mixed          $result;       // normalized form
    public float          $durationSec;
    public bool           $cacheHit;
}

final readonly class MethodInvocationFailedEvent {
    public MethodMetadata $method;
    public RpcParams      $params;
    public \Throwable     $exception;
    public float          $durationSec;
}
```

`Started` always fires first. Then exactly one of `Completed` or `Failed`.

For streaming methods, `Completed` fires the moment the iterator is returned
(before iteration). Three additional events let you track iteration itself:

```php
final readonly class StreamRowEmittedEvent {
    public MethodMetadata $method;
    public mixed          $row;
    public int            $index;
}

final readonly class StreamIterationCompletedEvent {
    public MethodMetadata $method;
    public int            $rowCount;
    public float          $durationSec;
}

final readonly class StreamIterationFailedEvent {
    public MethodMetadata $method;
    public \Throwable     $exception;
    public int            $rowCount;
    public float          $durationSec;
}
```

### Writing a subscriber

```php
use JsonRpcServer\Event\MethodInvocationCompletedEvent;
use JsonRpcServer\Event\MethodInvocationFailedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Audit $audit) {}

    public static function getSubscribedEvents(): array
    {
        return [
            MethodInvocationCompletedEvent::class => 'onCompleted',
            MethodInvocationFailedEvent::class    => 'onFailed',
        ];
    }

    public function onCompleted(MethodInvocationCompletedEvent $e): void
    {
        if ($this->audit->isAudited($e->method->name)) {
            $this->audit->log(
                method: $e->method->name,
                params: $e->params->all(),
                result: $e->result,
            );
        }
    }

    public function onFailed(MethodInvocationFailedEvent $e): void { /* … */ }
}
```

With Symfony's default `autoconfigure: true`, that's all the wiring you need.

### Event ordering with caching

On a cache **hit**:

```
Started   (params)
Completed (params, cached result, cacheHit=true, duration=0.0)
```

The handler never runs. On a **miss**, the handler runs and `Completed` fires
with the fresh result and `cacheHit=false`. On a **failure**, `Failed` fires
instead — `Completed` and `Failed` never both fire for the same call.

---

## Symfony Web Profiler

When `kernel.debug = true` and `symfony/web-profiler-bundle` is installed, the
bundle adds a **RPC** panel:

- one row per invocation in the request
- method name, handler class, normalized params
- status (`ok` / `error`), duration, cache-hit flag
- result preview (or exception + message on failures)

```yaml
json_rpc_server:
    profiler:
        enabled: true   # default; no-op outside kernel.debug
```

In production the subscriber is registered but never invoked — the framework
profiler itself is off, so the cost is nil. Leave `enabled: true`.

```bash
composer require --dev symfony/web-profiler-bundle
```

---

## PSR-3 logging

A built-in subscriber writes one log line per outcome — no code to write.

```yaml
json_rpc_server:
    logging:
        enabled: true
        channel: ~                  # null = the default `logger` service
                                    # e.g. monolog.logger.rpc for a dedicated channel
        level_started:   debug      # rpc.call.started
        level_completed: info       # rpc.call.completed
        level_failed:    warning    # rpc.call.failed
        log_params: true            # include request params in context
        log_result: false           # include result (verbose)
        slow_threshold_ms: ~        # escalate slow calls to level_failed
```

Output (illustrative):

```
[2026-05-22T15:00:00+00:00] app.INFO: rpc.call.completed
    {"method":"user.update","duration_ms":42,"cache_hit":false,"params":{"id":7}}
```

### Routing to a dedicated channel

```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['rpc']
    handlers:
        rpc_file:
            type: stream
            path: '%kernel.logs_dir%/rpc.log'
            channels: ['rpc']

# config/packages/json_rpc_server.yaml
json_rpc_server:
    logging:
        enabled: true
        channel: monolog.logger.rpc
```

When the built-in subscriber doesn't fit your needs (custom redaction,
sampling, structured fields) turn it off and write your own — the events are
the canonical extension point.

---

## Sentry

Installs into Sentry via `sentry/sentry-symfony`.

```bash
composer require sentry/sentry-symfony
```

```yaml
json_rpc_server:
    sentry:
        enabled: true
        breadcrumbs: true       # rpc-category breadcrumb on every call
        tag_method: true        # sets rpc.method tag while a call is in flight
        transactions: false     # child spans for Performance Monitoring
        ignore_exceptions:      # client-side errors stay invisible
            - JsonRpcServer\Exception\InvalidParamsException
            - JsonRpcServer\Exception\InvalidRequestException
            - JsonRpcServer\Exception\MethodNotFoundException
            - JsonRpcServer\Exception\ParseException
            - JsonRpcServer\Exception\AccessDeniedException
            - JsonRpcServer\Exception\RateLimitExceededException
```

In Sentry every issue from a handler gets:

- a `rpc.method=user.update` **tag** — filter and group by method;
- **breadcrumbs** with the last RPC calls before the crash;
- if `transactions: true`, a **child span** under the active transaction
  (`op: rpc.call`, `description: <method-name>`).

Unhandled exceptions still flow into Sentry via the PSR-3 logger as before —
this subscriber is purely about extra context. Exceptions in
`ignore_exceptions` skip the error breadcrumb / span. To filter them out of
Sentry entirely use a `before_send` hook in Sentry config.

The subscriber registers only when **both** flags are true: `enabled: true`
and `sentry/sentry-symfony` installed. Dev without Sentry silently no-ops.

---

## OpenTelemetry

Vendor-neutral. Works with Jaeger, Datadog, Grafana Tempo, Honeycomb, AWS
X-Ray, Google Cloud Trace, New Relic, Lightstep — anything that speaks OTLP.

```bash
composer require open-telemetry/sdk
# plus an exporter for your backend, e.g.:
# composer require open-telemetry/exporter-otlp
```

Initialize the SDK once per process (typically in `config/bootstrap.php` —
see the [PHP OTel SDK docs](https://opentelemetry.io/docs/languages/php/)).
The bundle picks the tracer / meter up from the OTel global provider.

```yaml
json_rpc_server:
    opentelemetry:
        enabled: true
        tracer_name: 'json-rpc'
        traces: true                # SERVER-kind span per RPC call
        metrics: true               # rpc.server.duration + rpc.server.requests
        propagate_traceparent: true # join the parent trace from upstream HTTP
        record_params: false        # attach params as span attribute (PII-warning)
        record_result: false        # attach result as span attribute (verbose)
        record_max_chars: 2048      # truncate the above
        stream:
            record_row_count: true  # cheap — sets rpc.stream.row_count attribute
            span_per_row: false     # expensive — one extra span per emitted row
        ignore_exceptions: [...]    # same set as Sentry
```

### What ends up in your backend

- **Span per call** with [OTel RPC semantic conventions](https://opentelemetry.io/docs/specs/semconv/rpc/json-rpc/):
  `rpc.system=jsonrpc`, `rpc.method=user.update`, `rpc.jsonrpc.version=2.0`,
  plus `rpc.jsonrpc.error_code` / `rpc.jsonrpc.error_message` on failure.
- **Metrics** `rpc.server.duration` (histogram, ms) and
  `rpc.server.requests` (counter) — both labelled by `rpc.method` and
  `outcome` (`ok` / `error`). Standard dashboards work without further setup.
- **Stream methods** — span stays open from dispatch until the iterator
  drains, carrying `rpc.stream.row_count` as a final attribute. Per-row
  spans available behind `span_per_row: true` when debugging row-level
  latency.
- **Distributed trace** — `traceparent` from the incoming HTTP request
  becomes the parent of the RPC span, so a trace started in a mobile client
  / API gateway continues seamlessly through your service.

The subscriber registers only when both `enabled: true` and
`open-telemetry/sdk` is installed. Without the SDK the bundle has zero
footprint.

### Custom attributes

Need an attribute the bundle doesn't add by default? Write your own
subscriber against the same events:

```php
public function onStarted(MethodInvocationStartedEvent $e): void
{
    Span::getCurrent()->setAttribute('app.tenant', $this->tenantResolver->id());
}
```

Span context is already active during the call thanks to the bundle's
subscriber — there's nothing else to wire.

---

## Combining stacks

Nothing stops you running all four at once. A typical production setup:

- **Logging**: ERROR-and-up to stdout for the platform log aggregator
- **Sentry**: exception breadcrumbs + tags
- **OpenTelemetry**: traces and metrics to a Tempo/Mimir backend
- **Profiler**: off in prod, on in dev via `kernel.debug`

The four subscribers don't talk to each other — they each read the events
independently. Order is unspecified and shouldn't matter.

---

## When to write your own subscriber

Built-in stacks cover the common shapes. Roll your own when:

- you need to **redact** specific param fields before they hit any backend;
- you need to **sample** (1% of `feed.list`, 100% of `payments.charge`);
- you need to **enrich** with information only your app knows (tenant id,
  feature flag bucket, A/B segment);
- you have a backend none of the built-ins support (Datadog StatsD direct,
  Graphite, Honeycomb-without-OTel-collector, …).

The events are the public API. Treat them as such.
