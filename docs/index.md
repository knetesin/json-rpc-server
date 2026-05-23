---
title: JsonRpc Server Bundle
description: Modern JSON-RPC 2.0 server for Symfony.
---

# JsonRpc Server Bundle

Modern JSON-RPC 2.0 server for Symfony — built around attributes, DTOs, and
the rest of the framework you already use. Speaks JSON-RPC for your own
clients, MCP for AI agents, and NDJSON / SSE when you need to stream.

```bash
composer require knetesin/json-rpc-server
```

```php
use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('user.update', roles: ['ROLE_USER'])]
final class UpdateUser
{
    public function __invoke(UpdateUserRequest $req, Context $ctx): UserResponse { /* … */ }
}
```

That's a full handler — no routing, no controllers, no manual validation.

## Read the docs

- **English** → [`docs/en/`](en/)
- **Русский** → [`docs/ru/`](ru/)

## Highlights

- Attribute-driven (`#[Rpc\Method]`, `#[Rpc\Cache]`, `#[Rpc\RateLimit]`,
  `#[Rpc\Stream]`, `#[Rpc\Mcp]`)
- DTOs through Symfony Serializer + Validator
- Streaming endpoint with NDJSON / SSE / JSON-array formats
- First-class MCP for LLM agents, five rendering formats incl. TOON
- Built-in PSR-3 logging, Symfony Web Profiler, Sentry bridge, OpenTelemetry
  bridge — all opt-in
- Compile-time method discovery — zero reflection in the hot path
- Safe defaults for long-running runtimes (RoadRunner, FrankenPHP, Swoole)

## Repository

[github.com/knetesin/json-rpc-server](https://github.com/knetesin/json-rpc-server) ·
[Packagist](https://packagist.org/packages/knetesin/json-rpc-server) ·
[Issues](https://github.com/knetesin/json-rpc-server/issues) ·
[Discussions](https://github.com/knetesin/json-rpc-server/discussions)

## License

[MIT](https://github.com/knetesin/json-rpc-server/blob/main/LICENSE).
