<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Mcp;

use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;

/**
 * Exposes the subset of RPC methods that should be visible via MCP — combining
 * the per-method `#[Rpc\Mcp]` attribute with bundle-level `expose_all`,
 * `exclude_prefixes` and `whitelist_methods` config.
 */
final class McpToolRegistry
{
    public function __construct(
        private readonly MethodRegistry $methods,
        private readonly McpToolFilter $filter,
        /**
         * Fallback only — modern metadata carries `inputSchema` precomputed by
         * MethodCompilerPass. Kept for compatibility with callers that build
         * MethodMetadata at runtime without going through the compiler pass
         * (e.g. tests, dynamic registration).
         */
        private readonly ?JsonSchemaBuilder $schemaBuilder = null,
    ) {
    }

    /**
     * @return list<array{name: string, description: ?string, roles: list<string>, inputSchema: array<string, mixed>}>
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->methods->all() as $meta) {
            if (!$this->filter->isExposed($meta)) {
                continue;
            }

            $tools[] = [
                'name' => $meta->name,
                'description' => $meta->getMcpDescription(),
                'roles' => $meta->roles,
                'inputSchema' => [] !== $meta->inputSchema
                    ? $meta->inputSchema
                    : ($this->schemaBuilder?->fromMethod($meta) ?? ['type' => 'object', 'properties' => new \stdClass()]),
            ];
        }

        return $tools;
    }

    public function hasTool(string $name): bool
    {
        if (!$this->methods->has($name)) {
            return false;
        }

        return $this->filter->isExposed($this->methods->get($name));
    }
}
