# 01 — Getting started

## Requirements

- PHP 8.3+ (the bundle uses typed class constants throughout)
- Symfony 7.x or 8.x
- `ext-json`

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

With Symfony Flex the bundle is auto-registered. Without Flex, add it to
`config/bundles.php`:

```php
return [
    // ...
    JsonRpcServer\JsonRpcServerBundle::class => ['all' => true],
];
```

## Minimal configuration

Zero config works out of the box. The defaults are documented in
[Configuration reference](./13-configuration.md). For a first run create an
empty file:

```yaml
# config/packages/json_rpc_server.yaml
json_rpc_server: ~
```

The bundle registers four routes:

| Route | Path | Method |
|---|---|---|
| `rpc` | `/rpc` | POST |
| `rpc_stream` | `/rpc/stream` | POST |
| `rpc_mcp_tools` | `/mcp/tools` | GET |
| `rpc_mcp_call` | `/mcp/call` | POST |

You can change these paths in `rpc.routes` or disable any of them.

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
