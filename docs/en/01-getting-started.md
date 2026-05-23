# 01 — Getting started

## Requirements

- PHP 8.3+ (the bundle uses typed class constants throughout)
- Symfony 7.x or 8.x
- `ext-json`
- `symfony/expression-language` (required by route `condition`s — installed automatically with the bundle)

Optional packages (`composer suggest`):

- `symfony/security-bundle` — needed only if any method declares `roles`
- `symfony/cache` — needed only for tag-aware cache invalidation
- `symfony/rate-limiter` — needed only if any method carries `#[Rpc\RateLimit]`
- `symfony/maker-bundle` — enables `bin/console make:rpc-method`
- `symfony/web-profiler-bundle` — enables the RPC panel in the toolbar

The bundle fails the container build with a clear message if you reference a
feature whose backing package is missing — you won't ship "silently broken".

## Installation

```bash
composer require knetesin/json-rpc-server
```

Requirements in the host app:

- `symfony/flex` (recommended) — applies the bundled recipe on `composer require`
- `symfony/routing` — route import (see below)

### Two config files (not one)

The bundle needs **both** files. They do different jobs:

| File | Purpose |
|---|---|
| `config/packages/json_rpc_server.yaml` | Bundle settings (paths, MCP, cache, …) |
| `config/routes/json_rpc_server.yaml` | **Registers** HTTP routes in Symfony |

Changing `json_rpc_server.routes` in the **packages** file only changes paths
on routes that are **already imported** — it does not create routes by itself.

**With Symfony Flex**, the recipe in the package (`.symfony/recipe/`) should copy
both files on first `composer require`. You should see:

```
config/packages/json_rpc_server.yaml
config/routes/json_rpc_server.yaml
```

**If the recipe did not run** (no `config/routes/json_rpc_server.yaml`, or no
`symfony.lock` entry for `knetesin/json-rpc-server`), add them manually:

```php
// config/bundles.php
return [
    // ...
    JsonRpcServer\JsonRpcServerBundle::class => ['all' => true],
];
```

```yaml
# config/routes/json_rpc_server.yaml  ← required
json_rpc_server:
    resource: '@JsonRpcServerBundle/Resources/config/routes.php'
    type: php
```

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server: ~
```

Then retry:

```bash
composer recipes:install knetesin/json-rpc-server --force -v
# or (older Flex): composer symfony:recipes:install knetesin/json-rpc-server --force -v
bin/console cache:clear
bin/console debug:router | grep rpc
```

> **Note:** Recipes for `knetesin/json-rpc-server` ship **inside the Composer
> package** (`.symfony/recipe/`). They are not in `symfony/recipes-contrib` yet,
> so some setups only apply them from `vendor/` on `composer require`. Manual
> files above always work.

## Routes and paths

Default paths (after the routes file is imported):

| Route | Path | Method | Default |
|---|---|---|---|
| `rpc` | `/rpc` | POST | enabled |
| `rpc_stream` | `/rpc/stream` | POST | **disabled** — flip `routes.stream.enabled: true` if any handler uses `#[Rpc\Stream]` |
| `rpc_mcp_tools` | `/mcp/tools` | GET | **disabled** — flip `mcp.enabled: true` |
| `rpc_mcp_call` | `/mcp/call` | POST | **disabled** — flip `mcp.enabled: true` |
| `rpc_openrpc` | `/rpc.openrpc.json` | GET | **disabled** — flip `routes.openrpc.enabled: true` to publish |

Override paths or disable a route in **packages** config:

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server:
    routes:
        mcp_tools: { path: /mcp2/tools, enabled: true }
```

See [Configuration reference](./13-configuration.md) for every `routes.*` knob.

## Your first handler

Create `src/Rpc/Add.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rpc;

use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('math.add', description: 'Add two integers.')]
final class Add
{
    /** @return array<string, int> */
    public function __invoke(int $a, int $b): array
    {
        return ['sum' => $a + $b];
    }
}
```

That's it. The bundle's compiler pass picks up every class carrying
`#[Rpc\Method]`, builds the metadata at container compile time, and routes
incoming calls automatically.

## Calling it

```bash
curl -X POST http://localhost/rpc \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"math.add","params":{"a":2,"b":3},"id":1}'
```

```json
{"jsonrpc":"2.0","result":{"sum":5},"id":1}
```

`params` can also be positional (`[2, 3]`) — see [Parameters & DTOs](./03-parameters.md).

## Inspecting what's registered

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

For details on one method:

```bash
bin/console debug:rpc math.add
```

## Scaffolding with maker

If `symfony/maker-bundle` is installed:

```bash
bin/console make:rpc-method UserGetByEmail \
    --method=user.getByEmail --with-dto --with-test
```

Generates:

- `src/Rpc/UserGetByEmail.php` — handler
- `src/Rpc/Dto/UserGetByEmailRequest.php` — DTO with `Assert\NotBlank` sample
- `tests/Rpc/UserGetByEmailTest.php` — functional test skeleton

## Next steps

- Add a DTO and validation: [Parameters & DTOs](./03-parameters.md)
- Restrict who can call it: [Security & roles](./04-security.md)
- Cache the result: [Caching](./05-caching.md)
- Expose it to MCP clients: [MCP](./08-mcp.md)
