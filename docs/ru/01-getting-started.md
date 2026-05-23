# 01 — Быстрый старт

## Требования

- PHP 8.3+ (бандл использует типизированные константы классов)
- Symfony 7.x или 8.x
- `ext-json`
- `symfony/expression-language` (нужен для `condition` на маршрутах — тянется с бандлом)

Опциональные пакеты (`composer suggest`):

- `symfony/security-bundle` — нужен только если хоть один метод объявляет `roles`
- `symfony/cache` — нужен только для tag-aware инвалидации кэша
- `symfony/rate-limiter` — нужен только если есть `#[Rpc\RateLimit]`
- `symfony/maker-bundle` — включает `bin/console make:rpc-method`
- `symfony/web-profiler-bundle` — добавляет панель RPC в Web Profiler

Если вы ссылаетесь на фичу, чей backing-пакет не установлен, бандл падает на
этапе сборки контейнера с понятным сообщением — silently broken не отгрузите.

## Установка

```bash
composer require knetesin/json-rpc-server
```

В приложении нужны:

- `symfony/flex` (рекомендуется) — на `composer require` применяет recipe из пакета
- `symfony/routing` — импорт маршрутов (см. ниже)

### Два файла конфигурации (не один)

Бандлу нужны **оба** файла — у них разные задачи:

| Файл | Зачем |
|---|---|
| `config/packages/json_rpc_server.yaml` | Настройки бандла (пути, MCP, кэш, …) |
| `config/routes/json_rpc_server.yaml` | **Регистрирует** HTTP-маршруты в Symfony |

Правка `json_rpc_server.routes` только в **packages** меняет пути у уже
импортированных маршрутов — сами маршруты этим файлом **не появляются**.

**С Symfony Flex** recipe из пакета (`.symfony/recipe/`) при первом
`composer require` должен скопировать оба файла:

```
config/packages/json_rpc_server.yaml
config/routes/json_rpc_server.yaml
```

**Если recipe не сработал** (нет `config/routes/json_rpc_server.yaml` или нет
записи в `symfony.lock` для `knetesin/json-rpc-server`) — добавьте вручную:

```php
// config/bundles.php
return [
    // ...
    Knetesin\JsonRpcServerBundle\KnetesinJsonRpcServerBundle::class => ['all' => true],
];
```

```yaml
# config/routes/json_rpc_server.yaml  ← обязателен
json_rpc_server:
    resource: '@KnetesinJsonRpcServerBundle/Resources/config/routes.php'
    type: php
```

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server: ~
```

Затем:

```bash
composer recipes:install knetesin/json-rpc-server --force -v
# или (старый Flex): composer symfony:recipes:install knetesin/json-rpc-server --force -v
bin/console cache:clear
bin/console debug:router | grep rpc
```

> Recipe лежит **внутри Composer-пакета** (`.symfony/recipe/`), а не в
> `symfony/recipes-contrib` — на части проектов он срабатывает только при
> установке из `vendor/`. Ручные файлы выше работают всегда.

## Маршруты и пути

Пути по умолчанию (после импорта routes-файла):

| Route | Path | Метод | Дефолт |
|---|---|---|---|
| `rpc` | `/rpc` | POST | включён |
| `rpc_stream` | `/rpc/stream` | POST | **выключен** — поставьте `routes.stream.enabled: true` если используете `#[Rpc\Stream]` |
| `rpc_mcp_tools` | `/mcp/tools` | GET | **выключен** — поставьте `mcp.enabled: true` |
| `rpc_mcp_call` | `/mcp/call` | POST | **выключен** — поставьте `mcp.enabled: true` |
| `rpc_openrpc` | `/rpc.openrpc.json` | GET | **выключен** — поставьте `routes.openrpc.enabled: true` чтобы публиковать |

Переопределение в **packages**:

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server:
    routes:
        mcp_tools: { path: /mcp2/tools, enabled: true }
```

Все опции — в [Configuration reference](./13-configuration.md).

## Первый handler

Создайте `src/Rpc/Add.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rpc;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('math.add', description: 'Сложить два числа.')]
final class Add
{
    /** @return array<string, int> */
    public function __invoke(int $a, int $b): array
    {
        return ['sum' => $a + $b];
    }
}
```

Всё. Compiler pass бандла подхватывает каждый класс с `#[Rpc\Method]`, строит
метаданные на этапе компиляции контейнера, и роутит вызовы автоматически.

## Вызов

```bash
curl -X POST http://localhost/rpc \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'
```

```json
{"jsonrpc":"2.0","result":{"sum":5},"id":1}
```

`params` можно передавать позиционно (`[2, 3]`) — см. [Параметры и DTO](./03-parameters.md).

## Что зарегистрировано

```bash
bin/console debug:rpc
```

```
RPC methods (1)
+-----------+-------+--------+-------+-----------+-----+------------+
| Name      | Class | Roles  | Cache | RateLimit | MCP | Deprecated |
+-----------+-------+--------+-------+-----------+-----+------------+
| math.add  | Add   | public | —     | —         | no  | —          |
+-----------+-------+--------+-------+-----------+-----+------------+
```

Подробности по одному методу:

```bash
bin/console debug:rpc math.add
```

## Скаффолдинг через maker

Если установлен `symfony/maker-bundle`:

```bash
bin/console make:rpc-method UserGetByEmail \
    --method=user.getByEmail --with-dto --with-test
```

Сгенерирует:

- `src/Rpc/UserGetByEmail.php` — handler
- `src/Rpc/Dto/UserGetByEmailRequest.php` — DTO с примером `Assert\NotBlank`
- `tests/Rpc/UserGetByEmailTest.php` — скелет функционального теста

## Дальше

- Добавить DTO и валидацию: [Параметры и DTO](./03-parameters.md)
- Ограничить доступ: [Безопасность и роли](./04-security.md)
- Закэшировать результат: [Кэширование](./05-caching.md)
- Выставить как MCP tool: [MCP](./08-mcp.md)
