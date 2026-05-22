<?php

declare(strict_types=1);

namespace JsonRpcServer\Mcp;

use JsonRpcServer\Attribute\McpFormat;
use JsonRpcServer\Registry\MethodMetadata;

/**
 * Turns a normalized RPC result into the `content[]` array of an MCP
 * `tools/call` response.
 *
 * The effective format is resolved by the controller (request override →
 * method attribute → bundle default) and passed in explicitly, so the
 * formatter itself is stateless and trivial to swap or decorate.
 *
 * Replace the default via a service alias:
 *
 *     services:
 *         JsonRpcServer\Mcp\McpResultFormatter:
 *             alias: App\Rpc\MyFormatter
 */
interface McpResultFormatter
{
    /**
     * @return list<array<string, mixed>> MCP content array
     */
    public function format(mixed $result, McpFormat $format, MethodMetadata $method): array;
}
