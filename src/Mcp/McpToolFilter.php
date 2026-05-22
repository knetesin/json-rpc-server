<?php

declare(strict_types=1);

namespace JsonRpcServer\Mcp;

use JsonRpcServer\Registry\MethodMetadata;

/**
 * Decides whether a given RPC method should be visible via MCP.
 *
 * Priority (first matching rule wins). Operator config (exclude_methods,
 * whitelist_methods) takes precedence over developer-set attributes — the
 * deployment owner gets the final say.
 *
 *   1. `exclude_methods` lists the name       → hidden  (operator: explicit deny)
 *   2. `whitelist_methods` lists the name     → exposed (operator: explicit allow)
 *   3. `#[Rpc\Mcp(enabled: false)]`           → hidden  (developer opt-out)
 *   4. method is deprecated AND no explicit
 *      #[Rpc\Mcp] opt-in                      → hidden  (auto-hide deprecated)
 *   5. name matches `exclude_prefixes`        → hidden  (operator: bulk deny)
 *   6. `expose_all = true`                    → exposed (operator: bulk allow)
 *   7. `#[Rpc\Mcp]` present (enabled: true)   → exposed (developer opt-in)
 *   8. otherwise                              → hidden
 */
final readonly class McpToolFilter
{
    /**
     * @param list<string> $excludePrefixes
     * @param list<string> $excludeMethods
     * @param list<string> $whitelistMethods
     */
    public function __construct(
        private bool $exposeAll,
        private array $excludePrefixes,
        private array $excludeMethods,
        private array $whitelistMethods,
    ) {
    }

    public function isExposed(MethodMetadata $meta): bool
    {
        if (\in_array($meta->name, $this->excludeMethods, true)) {
            return false;
        }
        if (\in_array($meta->name, $this->whitelistMethods, true)) {
            return true;
        }
        if ($meta->isMcpOptOut()) {
            return false;
        }
        // Deprecated methods are hidden from MCP unless explicitly opted in
        // (whitelist or an explicit #[Rpc\Mcp] attribute) — LLM agents should
        // not pick them up as fresh tools.
        if ($meta->isDeprecated() && !$meta->isMcpOptIn()) {
            return false;
        }
        foreach ($this->excludePrefixes as $prefix) {
            if (str_starts_with($meta->name, $prefix)) {
                return false;
            }
        }
        if ($this->exposeAll) {
            return true;
        }

        return $meta->isMcpOptIn();
    }
}
