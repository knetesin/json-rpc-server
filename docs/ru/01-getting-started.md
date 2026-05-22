# 01 — Быстрый старт

## Требования

- PHP 8.3+ (бандл использует типизированные константы классов)
- Symfony 7.x или 8.x
- `ext-json`

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

С Symfony Flex бандл регистрируется автоматически. Без Flex добавьте в
`config/bundles.php`:

```php
return [
    // ...
    JsonRpcServer\JsonRpcServerBundle::class => ['all' => true],
];
```

## Минимальная конфигурация

Zero config работает из коробки. Все дефолты документированы в
[Configuration reference](./13-configuration.md). Для первого запуска создайте
пустой файл:

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server: ~
```

Бандл регистрирует четыре роута:

| Route | Path | Метод |
|---|---|---|
| `rpc` | `/rpc` | POST |
| `rpc_stream` | `/rpc/stream` | POST |
| `rpc_mcp_tools` | `/mcp/tools` | GET |
| `rpc_mcp_call` | `/mcp/call` | POST |

Пути можно переопределить через `rpc.routes` или отключить любой из них.

## Первый handler

Создайте `src/Rpc/Add.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rpc;

use JsonRpcServer\Attribute as Rpc;

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
