# 12 — CLI и maker

Три console-команды. Все авто-регистрируются; ничего добавлять в
`services.yaml` проекта не надо.

## `debug:rpc`

Зеркалит `debug:router` / `debug:event-dispatcher` — список всех методов
или drill-in одного:

### Список всего

```bash
bin/console debug:rpc
```

```
RPC methods (4)
+-----------------+-------------------+-----------------+-----------+-----------+-----+------------+
| Name            | Class             | Roles           | Cache     | RateLimit | MCP | Deprecated |
+-----------------+-------------------+-----------------+-----------+-----------+-----+------------+
| user.delete     | DeleteUser        | ROLE_ADMIN [any]| —         | —         | no  | —          |
| user.get        | GetUser           | ROLE_USER [any] | 60s       | —         | yes | —          |
| user.legacy_get | LegacyGet         | ROLE_USER [any] | —         | —         | no  | yes        |
| weather.get     | GetWeather        | public          | 300s      | 30/60s(ip,fixed_window) | yes | — |
+-----------------+-------------------+-----------------+-----------+-----------+-----+------------+
```

### Detail по одному методу

```bash
bin/console debug:rpc user.get
```

```
user.get
========

+-------------------+--------------------------------------------+
| Property          | Value                                      |
+-------------------+--------------------------------------------+
| Class             | App\Rpc\GetUser                            |
| Description       | Look up a user by email                    |
| Deprecated        | —                                          |
| Return type       | array                                      |
| Roles             | ROLE_USER [any]                            |
| Streaming         | —                                          |
| Cache             | 60s scope=UserScope                        |
| Rate limit        | —                                          |
| MCP exposure      | yes                                        |
| Positional DTO    | rejected                                   |
| Reject unknown    | yes                                        |
| Max request       | default                                    |
+-------------------+--------------------------------------------+

Parameters
----------
+----------+----------------------+------+----------+--------------------+
| Name     | Type                 | Kind | Default  | Constraints        |
+----------+----------------------+------+----------+--------------------+
| $req     | App\Rpc\Dto\GetUser  | DTO  | required | —                  |
| $ctx     | Context              | ...  | required | —                  |
+----------+----------------------+------+----------+--------------------+

MCP input schema
----------------
{
    "type": "object",
    "properties": { ... },
    "required": ["email"],
    "additionalProperties": false
}
```

### Эмит OpenRPC

```bash
bin/console debug:rpc --openrpc \
    --title="Billing API" \
    --api-version="2.4.0" \
    > openrpc.json
```

См. [OpenRPC](./09-openrpc.md).

## `rpc:cache:clear`

Три режима (взаимно исключающих):

### Дропнуть все entry одного метода

```bash
bin/console rpc:cache:clear user.profile
```

Требует tag-aware пул. Failure если пул не tag-aware или метод неизвестен.

### Дропнуть по тегу

```bash
bin/console rpc:cache:clear --tag=user:42 --tag=tenant:acme
```

Дропает всё со штампом любого из этих тегов. Теги OR'ятся (любой match →
clear).

### Wipe пула целиком

```bash
bin/console rpc:cache:clear --all                    # default pool
bin/console rpc:cache:clear --all --pool=long_lived  # конкретный пул
```

`--all` очищает всё в пуле — не только RPC entries.

### Выводы

- Success: `Cleared cache entries for method "user.profile".` (exit 0)
- Method unknown / нет entries: warning (exit 1)
- Пул не tag-aware для tag-режима: warning (exit 1)
- Неправильное сочетание флагов: error (exit 2)

Все операции пишутся в info-лог через PSR-3 — годятся как audit-сигнал.

## `make:rpc-method`

Требует `symfony/maker-bundle` (`composer require symfony/maker-bundle --dev`).
Скаффолдит новый RPC-метод, опционально с DTO и тестом.

### Интерактивный режим

```bash
$ bin/console make:rpc-method

 PHP class name for the handler (e.g. UserGetByEmail):
 > UserGetByEmail

 JSON-RPC method name (default: user.get_by_email):
 > user.getByEmail

 Generate a request DTO? (yes/no) [yes]:
 > yes

 Generate a functional test? (yes/no) [yes]:
 > yes

 created: src/Rpc/UserGetByEmail.php
 created: src/Rpc/Dto/UserGetByEmailRequest.php
 created: tests/Rpc/UserGetByEmailTest.php

          
 Success!
          

 Next: open App\Rpc\UserGetByEmail and fill in the handler body.
 Then call it: POST /rpc with {"jsonrpc":"2.0","method":"user.getByEmail",...}
```

### Non-interactive

```bash
bin/console make:rpc-method UserGetByEmail \
    --method=user.getByEmail \
    --with-dto \
    --with-test
```

### Сгенерированные файлы

**`src/Rpc/UserGetByEmail.php`**

```php
<?php

declare(strict_types=1);

namespace App\Rpc;

use App\Rpc\Dto\UserGetByEmailRequest;
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Context\Context;

#[Rpc\Method('user.getByEmail')]
final class UserGetByEmail
{
    /** @return array<string, mixed> */
    public function __invoke(UserGetByEmailRequest $request, Context $ctx): array
    {
        // TODO: implement the method.
        return ['ok' => true];
    }
}
```

**`src/Rpc/Dto/UserGetByEmailRequest.php`** (с `--with-dto`)

```php
<?php

declare(strict_types=1);

namespace App\Rpc\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UserGetByEmailRequest
{
    public function __construct(
        // Образец — замените на свои поля.
        #[Assert\NotBlank]
        public string $id,
    ) {}
}
```

**`tests/Rpc/UserGetByEmailTest.php`** (с `--with-test`)

Функциональный тест с `KernelTestCase` и `Request::create`, дёргающий `/rpc`,
парсящий JSON-RPC envelope, со скелетом для assertion'ов. См. сгенерированный
файл.

### Эвристика имени

`UserGetByEmail` → `user.get_by_email` (snake_case после первого слова).
Override через `--method`. Эвристика просто даёт sane-дефолт в интерактиве.

### Куда кладутся файлы

| Тип | Путь |
|---|---|
| Handler | `src/Rpc/<ClassName>.php` |
| DTO | `src/Rpc/Dto/<ClassName>Request.php` |
| Test | `tests/Rpc/<ClassName>Test.php` |

Стандартное Symfony соглашение. Maker не пытается читать namespace'ы вашего
проекта — он выбирает `App\Rpc`, который у большинства проектов. Двигайте и
переименовывайте руками если структура другая (очень редко).
