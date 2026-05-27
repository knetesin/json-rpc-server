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
     * @return list<array{name: string, description: ?string, roles: list<string>, inputSchema: array<string, mixed>, outputSchema?: array<string, mixed>, annotations?: array<string, bool|string>}>
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->methods->all() as $meta) {
            if (!$this->filter->isExposed($meta)) {
                continue;
            }

            $entry = [
                'name' => $meta->name,
                'description' => $meta->getMcpDescription(),
                'roles' => $meta->roles,
                'inputSchema' => [] !== $meta->inputSchema
                    ? $meta->inputSchema
                    : ($this->schemaBuilder?->fromMethod($meta) ?? ['type' => 'object', 'properties' => new \stdClass()]),
            ];
            // outputSchema is omitted when the method's return type is too loose
            // to schema-ize (array/mixed/void/missing). MCP clients then know
            // "no advertised shape" rather than getting a meaningless stub.
            if ([] !== $meta->outputSchema) {
                $entry['outputSchema'] = $meta->outputSchema;
            }
            // annotations is skipped entirely when no hint applies — clients
            // then fall back to MCP-spec defaults (readOnlyHint=false, …)
            // rather than seeing a confusing empty object.
            if ([] !== $meta->mcpAnnotations) {
                $entry['annotations'] = $meta->mcpAnnotations;
            }

            $tools[] = $entry;
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
