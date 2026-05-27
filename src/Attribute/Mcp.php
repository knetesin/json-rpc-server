<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

/**
 * Marks an RPC method as exposed via the MCP tool list.
 *
 * The bundle's MCP endpoints (`/mcp/tools`, `/mcp/call` by default) surface
 * methods that carry this attribute (or are included via bundle config). The
 * `description` falls back to `#[Rpc\Method(description: ...)]` if omitted.
 *
 * `format` controls how the call result is rendered into `content[]`. Leave
 * it `null` to use the bundle-level default (`json_rpc_server.mcp.default_format`).
 *
 * `title` and the four `*Hint` flags map 1:1 to MCP `tools/list[].annotations`
 * (spec 2025-06-18). They are **advisory** — clients and LLMs use them to
 * decide whether to ask the user before invoking a tool, throttle retries,
 * etc., but cannot rely on them for security. Leave any field `null` to keep
 * the MCP-defined default and let the bundle apply auto-derivation rules (see
 * {@see \Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodCompilerPass}):
 *
 *   - A method that carries `#[Rpc\Cache]` is assumed read-only and idempotent
 *     — both flags default to `true` unless explicitly set here.
 *
 * Auto-derivation only fills `null` slots; an explicit `false` always wins.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Mcp
{
    /**
     * @param string|null $title human-friendly display label for the tool. Falls back to the RPC method name client-side when null.
     * @param bool|null $readOnlyHint true if calling the tool never modifies environment state.
     *                                MCP-spec default: false. Auto-derived to true when `#[Rpc\Cache]` is present.
     * @param bool|null $destructiveHint true if the tool may delete or otherwise destructively mutate state.
     *                                   MCP-spec default: true (only meaningful when readOnlyHint=false).
     * @param bool|null $idempotentHint true if repeating the call with the same arguments has no additional effect.
     *                                  MCP-spec default: false. Auto-derived to true when `#[Rpc\Cache]` is present.
     * @param bool|null $openWorldHint true if the tool can reach external systems (3rd-party APIs, internet).
     *                                 MCP-spec default: true.
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly bool $enabled = true,
        public readonly ?McpFormat $format = null,
        public readonly ?string $title = null,
        public readonly ?bool $readOnlyHint = null,
        public readonly ?bool $destructiveHint = null,
        public readonly ?bool $idempotentHint = null,
        public readonly ?bool $openWorldHint = null,
    ) {
    }
}
