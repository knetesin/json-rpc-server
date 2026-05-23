# 11 — Наблюдаемость

Бандл приносит четыре observability-стека из коробки — все opt-in, все
слушают одни и те же PSR-14 события. Стеки можно комбинировать; ничто ни с
чем не конфликтует.

| Стек | Включатель | Где удобен |
|---|---|---|
| [PSR-14 события](#psr-14-события) | всегда работает | свой listener |
| [Symfony Web Profiler](#symfony-web-profiler) | `json_rpc_server.profiler.enabled` (только debug) | локальная разработка |
| [PSR-3 логирование](#psr-3-логирование) | `json_rpc_server.logging.enabled` | структурированные app-логи |
| [Sentry](#sentry) | `json_rpc_server.sentry.enabled` | issue-трекинг с breadcrumbs / тегами / спанами |
| [OpenTelemetry](#opentelemetry) | `json_rpc_server.opentelemetry.enabled` | vendor-нейтральные трейсы / метрики / propagation |

---

## PSR-14 события

Dispatcher эмитит три события на каждый RPC-вызов:

```php
namespace Knetesin\JsonRpcServerBundle\Event;

final readonly class MethodInvocationStartedEvent {
    public MethodMetadata $method;
    public RpcParams      $params;
}

final readonly class MethodInvocationCompletedEvent {
    public MethodMetadata $method;
    public RpcParams      $params;
    public mixed          $result;       // нормализованная форма
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

`Started` фаирится, как только известно имя метода (после registry lookup,
до resolution аргументов). Затем ровно одно из `Completed` или `Failed`.

Поэтому **клиентские ошибки** (`InvalidParamsException` / -32602,
`AccessDeniedException`, rate limit, denormalize) всё равно дают
`rpc.call.failed`, строку в Web Profiler RPC и опционально Sentry/OTel — handler
может не выполниться, observability — да.

Для стриминговых методов `Completed` фаирится сразу как итератор возвращён
(до начала итерации). Три дополнительных события дают трекать саму итерацию:

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

### Свой subscriber

```php
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
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

С дефолтным `autoconfigure: true` ничего больше регистрировать не нужно.

### Порядок событий при кэше

На cache **hit**:

```
Started   (params)
Completed (params, cached result, cacheHit=true, duration=0.0)
```

Handler не вызывается. На **miss** handler отрабатывает и `Completed` фаирится
со свежим результатом и `cacheHit=false`. На **failure** фаирится `Failed` —
`Completed` и `Failed` никогда не фаирятся для одного и того же вызова.

---

## Symfony Web Profiler

Когда `kernel.debug = true` и установлен `symfony/web-profiler-bundle`,
бандл добавляет панель **RPC**:

- **сводка реестра** — сколько методов зарегистрировано, сколько exposed в MCP,
  streaming, deprecated, с ролями, с кешем и rate limit (из compile-time metadata;
  видна даже если в запросе не было RPC-вызовов)
- **этот запрос** — число вызовов, уникальных методов, суммарное время, ошибки,
  cache hits
- строка на каждый вызов: метод, handler, params, статус, время, результат
  (или exception)
- **batch dispatches** — решение fan-out, размер batch, тайминги sub-call'ов
  (если сработал parallel batch)

```yaml
json_rpc_server:
    profiler:
        enabled: true   # дефолт; no-op вне kernel.debug
```

В продакшене subscriber зарегистрирован, но никогда не вызывается —
framework-профайлер выключен, нагрузка нулевая. Оставляйте `enabled: true`.

```bash
composer require --dev symfony/web-profiler-bundle
```

---

## PSR-3 логирование

Встроенный subscriber пишет одну строку лога на исход — без своего кода.

```yaml
json_rpc_server:
    logging:
        enabled: true
        channel: ~                  # null = дефолтный сервис `logger`
                                    # например monolog.logger.rpc для отдельного канала
        level_started:   debug      # rpc.call.started
        level_completed: info       # rpc.call.completed
        level_failed:    warning    # rpc.call.failed
        log_params: true            # включать params в контекст
        log_result: false           # включать result (бывает шумно)
        slow_threshold_ms: ~        # эскалировать медленные вызовы до level_failed
```

Пример вывода:

```
[2026-05-22T15:00:00+00:00] app.INFO: rpc.call.completed
    {"method":"user.update","duration_ms":42,"cache_hit":false,"params":{"id":7}}
```

### Отдельный monolog-канал

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

Если встроенного subscriber'а не хватает (редакция PII-полей, sampling, свои
структурированные поля) — выключите его и напишите свой; события и есть
канонический extension point.

---

## Sentry

Подключается через `sentry/sentry-symfony`.

```bash
composer require sentry/sentry-symfony
```

```yaml
json_rpc_server:
    sentry:
        enabled: true
        breadcrumbs: true       # rpc-категория breadcrumb на каждый вызов
        tag_method: true        # ставит тег rpc.method на время вызова
        transactions: false     # child-спаны для Performance Monitoring
        ignore_exceptions:      # клиентские ошибки в Sentry не идут
            - Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException
            - Knetesin\JsonRpcServerBundle\Exception\InvalidRequestException
            - Knetesin\JsonRpcServerBundle\Exception\MethodNotFoundException
            - Knetesin\JsonRpcServerBundle\Exception\ParseException
            - Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException
            - Knetesin\JsonRpcServerBundle\Exception\RateLimitExceededException
```

В Sentry каждое issue из handler'а получает:

- **тег** `rpc.method=user.update` — фильтр и группировка по методу;
- **breadcrumbs** с последними RPC-вызовами до падения;
- если `transactions: true` — **child-span** в активной транзакции
  (`op: rpc.call`, `description: <имя-метода>`).

Неперехваченные исключения и так уходят в Sentry через PSR-3 логгер — этот
subscriber только добавляет контекст. Исключения из `ignore_exceptions`
пропускают error-breadcrumb / span. Полностью отфильтровать их из Sentry —
через `before_send` хук в Sentry-конфиге.

Subscriber подключается только если **оба** условия выполнены: `enabled: true`
И установлен `sentry/sentry-symfony`. Без SDK тихо no-op.

---

## OpenTelemetry

Vendor-нейтральный. Работает с Jaeger, Datadog, Grafana Tempo, Honeycomb, AWS
X-Ray, Google Cloud Trace, New Relic, Lightstep — со всем что говорит OTLP.

```bash
composer require open-telemetry/sdk
# плюс экспортёр под ваш бэкенд, например:
# composer require open-telemetry/exporter-otlp
```

Инициализируйте SDK один раз на процесс (обычно в `config/bootstrap.php` —
см. [PHP OTel SDK docs](https://opentelemetry.io/docs/languages/php/)).
Бандл подтягивает tracer / meter из глобального OTel-провайдера.

```yaml
json_rpc_server:
    opentelemetry:
        enabled: true
        tracer_name: 'json-rpc'
        traces: true                # SERVER-kind span на каждый RPC-вызов
        metrics: true               # rpc.server.duration + rpc.server.requests
        propagate_traceparent: true # подцепляться к parent trace из HTTP
        record_params: false        # пишем params в атрибуты (PII-warning)
        record_result: false        # пишем result в атрибуты (бывает шумно)
        record_max_chars: 2048      # truncation выше
        stream:
            record_row_count: true  # дёшево — атрибут rpc.stream.row_count
            span_per_row: false     # дорого — отдельный span на каждую строку
        ignore_exceptions: [...]    # тот же набор что у Sentry
```

### Что уходит в бэкенд

- **Span на каждый вызов** с [OTel RPC semantic conventions](https://opentelemetry.io/docs/specs/semconv/rpc/json-rpc/):
  `rpc.system=jsonrpc`, `rpc.method=user.update`, `rpc.jsonrpc.version=2.0`,
  плюс `rpc.jsonrpc.error_code` / `rpc.jsonrpc.error_message` на ошибках.
- **Метрики** `rpc.server.duration` (histogram, мс) и
  `rpc.server.requests` (counter) — обе с label'ами `rpc.method` и
  `outcome` (`ok` / `error`). Стандартные дашборды работают без настройки.
- **Стрим-методы** — span остаётся открытым от dispatch'а до исчерпания
  итератора, с атрибутом `rpc.stream.row_count` в финале. Per-row спаны
  доступны через `span_per_row: true` для дебага row-level latency.
- **Distributed trace** — `traceparent` из incoming HTTP-запроса становится
  parent'ом RPC-спана, и трейс из мобильного клиента / API gateway уходит
  сквозь ваш сервис.

Subscriber подключается только если `enabled: true` И установлен
`open-telemetry/sdk`. Без SDK нулевой футпринт.

### Свои атрибуты

Нужен атрибут которого нет в дефолте? Свой subscriber на те же события:

```php
public function onStarted(MethodInvocationStartedEvent $e): void
{
    Span::getCurrent()->setAttribute('app.tenant', $this->tenantResolver->id());
}
```

Span-context уже активен на время вызова благодаря бандлу — больше ничего
заводить не надо.

---

## Комбинирование стеков

Ничто не мешает запустить всё одновременно. Типичная прод-настройка:

- **Logging**: ERROR-и-выше в stdout для аггрегатора платформы
- **Sentry**: breadcrumbs и теги для exception-issues
- **OpenTelemetry**: трейсы и метрики в Tempo/Mimir
- **Profiler**: off в prod, on в dev через `kernel.debug`

Четыре subscriber'а друг с другом не общаются — каждый читает события
независимо. Порядок не специфицирован и не должен иметь значения.

---

## Когда писать свой subscriber

Встроенные стеки покрывают типовые формы. Свой нужен когда:

- надо **редактировать** конкретные поля params до того как они уйдут в backend;
- надо **сэмплить** (1% от `feed.list`, 100% от `payments.charge`);
- надо **обогащать** контекстом который знает только ваше приложение
  (tenant id, feature-flag сегмент, A/B бакет);
- бэкенд не поддерживается встроенными (Datadog StatsD напрямую, Graphite,
  Honeycomb без OTel-collector'а, …).

События — публичный API. Относитесь к ним соответственно.
