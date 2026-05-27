<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Registry;

use Knetesin\JsonRpcServerBundle\Attribute\Cache;
use Knetesin\JsonRpcServerBundle\Attribute\McpFormat;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\Attribute\RoleMatch;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;

final readonly class MethodMetadata
{
    /**
     * @param list<string> $roles
     * @param list<ParameterMetadata> $parameters
     * @param array<string, mixed> $inputSchema JSON Schema for MCP tool input,
     *                                          precomputed at container compile time
     * @param array<string, mixed> $outputSchema JSON Schema for the method's response,
     *                                           precomputed at container compile time
     *                                           from `#[Rpc\Method(outputSchema: …)]`
     *                                           or, when absent, from `__invoke()`'s
     *                                           return type. Empty array when the
     *                                           return type is not informative
     *                                           (`array`/`mixed`/`void`/missing).
     */
    public function __construct(
        public string $name,
        public string $serviceClass,
        public array $roles,
        public ?string $description,
        public array $parameters,
        public ?string $returnType,
        public bool $isStreaming,
        public ?StreamFormat $streamFormat,
        public RoleMatch $rolesMatch = RoleMatch::Any,
        public bool $allowPositionalDto = false,
        public bool $rejectUnknown = true,
        public ?string $deprecated = null,
        /** True iff the class carries a #[Rpc\Mcp] attribute (regardless of its `enabled` flag). */
        public bool $hasMcpAttribute = false,
        /** Value of the attribute's `enabled` flag. Meaningful only when hasMcpAttribute is true. */
        public bool $mcpEnabled = true,
        public ?string $mcpDescription = null,
        public McpFormat $mcpFormat = McpFormat::Json,
        public ?RateLimit $rateLimit = null,
        public ?Cache $cache = null,
        public ?int $maxRequestSize = null,
        public array $inputSchema = [],
        public array $outputSchema = [],
        /**
         * Resolved MCP `tools/list[].annotations` payload — empty array when no
         * field is set. Keys are MCP-spec field names (`title`, `readOnlyHint`,
         * `destructiveHint`, `idempotentHint`, `openWorldHint`); values are
         * already coerced (bool|string) so the controller can emit them verbatim.
         *
         * @var array<string, bool|string>
         */
        public array $mcpAnnotations = [],
    ) {
    }

    /** Convenience: attribute present AND not explicitly disabled. */
    public function isMcpOptIn(): bool
    {
        return $this->hasMcpAttribute && $this->mcpEnabled;
    }

    /** True if the attribute is present AND explicitly disabled (enabled: false). */
    public function isMcpOptOut(): bool
    {
        return $this->hasMcpAttribute && !$this->mcpEnabled;
    }

    public function getMcpDescription(): ?string
    {
        return $this->mcpDescription ?? $this->description;
    }

    public function getDtoParameter(): ?ParameterMetadata
    {
        foreach ($this->parameters as $p) {
            if ($p->isDto) {
                return $p;
            }
        }

        return null;
    }

    public function isDeprecated(): bool
    {
        return null !== $this->deprecated;
    }
}
