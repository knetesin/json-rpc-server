<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Method
{
    /**
     * @param list<string> $roles
     * @param RoleMatch|null $rolesMatch null uses {@see Configuration} `json_rpc_server.security.roles_match` (default: any)
     * @param bool|null $allowPositionalDto null uses {@see Configuration} `json_rpc_server.params.allow_positional_dto` (default: false).
     *                                      Set true to accept `"params":[…]` for handlers with a single DTO parameter.
     *                                      Note: enabling this binds the DTO constructor argument order into the public API
     *                                      — reordering ctor params becomes a breaking change.
     * @param bool|null $rejectUnknown null uses {@see Configuration} `json_rpc_server.params.reject_unknown` (default: true).
     *                                 When true, DTO denormalization fails on unknown fields — catches client typos
     *                                 and stale legacy keys. Set false for endpoints that must accept extra params
     *                                 silently (backward compatibility).
     * @param string|null $deprecated non-null marks the method as deprecated; the value is the reason / migration hint
     *                                shown to clients (logged on every call, sent as Deprecation/Sunset hints when
     *                                appropriate). Hidden from MCP tools by default — restore via
     *                                {@see Configuration} `json_rpc_server.mcp.whitelist_methods` or an explicit #[Rpc\Mcp].
     * @param class-string|array<string, mixed>|null $outputSchema overrides the response JSON Schema published in
     *                                                             MCP `tools/list[].outputSchema` and OpenRPC `result.schema`.
     *                                                             By default the schema is derived from `__invoke()`'s
     *                                                             return type — pass a class-string to schema-ize a
     *                                                             different DTO/enum via the bundle's JsonSchemaBuilder,
     *                                                             or a literal JSON Schema array for fully custom output.
     *                                                             Advisory only: the schema is a hint for clients/LLMs
     *                                                             and is never validated against the actual response.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $roles = [],
        public readonly ?RoleMatch $rolesMatch = null,
        public readonly ?bool $allowPositionalDto = null,
        public readonly ?bool $rejectUnknown = null,
        public readonly ?string $deprecated = null,
        public readonly ?string $description = null,
        public readonly string|array|null $outputSchema = null,
    ) {
    }
}
