<?php

declare(strict_types=1);

namespace JsonRpcServer\Attribute;

/**
 * Marks an RPC method as exposed via the MCP tool list.
 *
 * The bundle's MCP endpoints (`/mcp/tools`, `/mcp/call` by default) surface
 * methods that carry this attribute (or are included via bundle config). The
 * `description` falls back to `#[Rpc\Method(description: ...)]` if omitted.
 *
 * `format` controls how the call result is rendered into `content[]`. Leave
 * it `null` to use the bundle-level default (`json_rpc_server.mcp.default_format`).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Mcp
{
    public function __construct(
        public readonly ?string $description = null,
        public readonly bool $enabled = true,
        public readonly ?McpFormat $format = null,
    ) {
    }
}
