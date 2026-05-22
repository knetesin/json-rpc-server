<?php

declare(strict_types=1);

namespace JsonRpcServer\Mcp;

/**
 * Optional hook for RPC method classes that need to reshape their result
 * specifically for MCP consumption. Implement it on the handler class itself
 * when the JSON-RPC return shape carries fields that an LLM does not need or
 * should not see (debug info, internal IDs, large opaque blobs, etc.).
 *
 * Example:
 *
 *     #[Rpc\Method('user.getById')]
 *     #[Rpc\Mcp]
 *     final class GetById implements McpResultTransformer
 *     {
 *         public function __invoke(GetByIdRequest $req): UserResponse { ... }
 *
 *         public function transformMcpResult(mixed $result): mixed
 *         {
 *             // $result here is the already-normalized array form of
 *             // UserResponse — strip internal-only keys before the LLM sees them.
 *             unset($result['internalDebugFlags'], $result['cacheKey']);
 *             return $result;
 *         }
 *     }
 *
 * The transformer runs AFTER the Dispatcher normalizes the result, so the
 * argument is always an array, scalar, or null — never a raw DTO. This keeps
 * the contract stable regardless of whether the value came from a fresh call
 * or from the response cache.
 *
 * For batch reshaping across many methods, prefer a custom McpResultFormatter
 * that inspects the MethodMetadata.
 */
interface McpResultTransformer
{
    public function transformMcpResult(mixed $result): mixed;
}
