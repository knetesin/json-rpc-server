# Документация

Полный гайд по JsonRpc Server Bundle. Главы написаны так, чтобы их можно было
прочитать сверху вниз при первом знакомстве, а потом возвращаться по
отдельности.

| # | Глава | О чём |
|---|---|---|
| 01 | [Быстрый старт](./01-getting-started.md) | Установка, регистрация бандла, первый handler, первый вызов |
| 02 | [Методы](./02-methods.md) | `#[Rpc\Method]`, `__invoke`, возвращаемые типы, batch, notifications, deprecation |
| 03 | [Параметры и DTO](./03-parameters.md) | Денормализация DTO, `#[Rpc\Param]`, `RpcParams`, positional vs named, даты |
| 04 | [Безопасность и роли](./04-security.md) | `roles`, `RoleMatch`, интеграция с security-core, скрытие имён ролей |
| 05 | [Кэширование](./05-caching.md) | `#[Rpc\Cache]`, scope, pools, теги, `RpcCacheInvalidator` |
| 06 | [Rate limiting](./06-rate-limiting.md) | `#[Rpc\RateLimit]`, четыре политики, три scope'а, `Retry-After` |
| 07 | [Стриминг](./07-streaming.md) | `#[Rpc\Stream]`, NDJSON / SSE / JSON-array, ошибки в середине потока |
| 08 | [MCP](./08-mcp.md) | Список tools, invoke, форматы (включая TOON), фильтр, transformer |
| 09 | [OpenRPC](./09-openrpc.md) | Экспорт спеки, интеграция с SDK-генераторами |
| 10 | [Ошибки](./10-errors.md) | Иерархия исключений, JSON-RPC коды, кастомные серверные ошибки |
| 11 | [Наблюдаемость](./11-observability.md) | События, профайлер, PSR-3 логи, Sentry, OpenTelemetry |
| 12 | [CLI и maker](./12-cli-and-maker.md) | `debug:rpc`, `rpc:cache:clear`, `make:rpc-method` |
| 13 | [Конфигурация](./13-configuration.md) | Каждый YAML-ключ с дефолтами и рекомендациями |
| 14 | [Context](./14-context.md) | Объект `Context`, request id, propagation |

English version: [`docs/en/`](../en/README.md).

## Соглашения

- `composer require knetesin/json-rpc-server` — это всегда про бандл.
- `App\…` — namespace вашего приложения.
- Блоки `yaml` — валидная Symfony-конфигурация (кладите в
  `config/packages/json_rpc_server.yaml`).
- JSON-RPC примеры используют каноничный envelope 2.0:
  ```json
  { "jsonrpc": "2.0", "method": "…", "params": …, "id": 1 }
  ```
