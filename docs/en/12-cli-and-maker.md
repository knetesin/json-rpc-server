# 12 — CLI & maker

Three console commands. All are auto-registered; nothing to add to your
project's `services.yaml`.

## `debug:rpc`

Mirrors `debug:router` / `debug:event-dispatcher` — lists every registered
method, or drills into one:

### List everything

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

### Drill into one method

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

### Emit OpenRPC

```bash
bin/console debug:rpc --openrpc \
    --title="Billing API" \
    --api-version="2.4.0" \
    > openrpc.json
```

See [OpenRPC](./09-openrpc.md).

## `rpc:cache:clear`

Three modes (mutually exclusive):

### Drop all entries of one method

```bash
bin/console rpc:cache:clear user.profile
```

Requires a tag-aware pool. Returns failure if the pool isn't tag-aware or the
method is unknown.

### Drop by tag

```bash
bin/console rpc:cache:clear --tag=user:42 --tag=tenant:acme
```

Drops every entry stamped with any of these tags. Tags are listed; OR-semantics
(any match → cleared).

### Wipe a whole pool

```bash
bin/console rpc:cache:clear --all                    # default pool
bin/console rpc:cache:clear --all --pool=long_lived  # specific pool
```

`--all` clears everything in the pool — not just RPC entries.

### Outputs

- Success: `Cleared cache entries for method "user.profile".` (exit 0)
- Method unknown / no entries: warning (exit 1)
- Pool not tag-aware for a tag mode: warning (exit 1)
- Wrong combination of flags: error (exit 2)

All operations are info-logged via PSR-3 — usable as audit signal.

## `make:rpc-method`

Requires `symfony/maker-bundle` (`composer require symfony/maker-bundle --dev`).
Scaffolds a new RPC method, optionally with DTO and test.

### Interactive

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

### Generated files

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

**`src/Rpc/Dto/UserGetByEmailRequest.php`** (with `--with-dto`)

```php
<?php

declare(strict_types=1);

namespace App\Rpc\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UserGetByEmailRequest
{
    public function __construct(
        // Sample field — replace with your own.
        #[Assert\NotBlank]
        public string $id,
    ) {}
}
```

**`tests/Rpc/UserGetByEmailTest.php`** (with `--with-test`)

A functional test using `KernelTestCase` and `Request::create` that hits
`/rpc`, parses the JSON-RPC envelope, and gives you a template to add
assertions. See generated file.

### Naming heuristic

`UserGetByEmail` → `user.get_by_email` (snake_case after the first word).
Override with `--method`. The heuristic just gives you a sane default in
interactive mode.

### Where the files go

| Type | Path |
|---|---|
| Handler | `src/Rpc/<ClassName>.php` |
| DTO | `src/Rpc/Dto/<ClassName>Request.php` |
| Test | `tests/Rpc/<ClassName>Test.php` |

Standard Symfony convention. The maker doesn't try to read your project's
namespaces — it picks `App\Rpc`, which is what most apps use. Move and rename
manually if your structure differs (very rare).
