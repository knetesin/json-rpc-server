<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Mcp;

use Knetesin\JsonRpcServerBundle\Attribute\McpFormat;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;

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
 *         Knetesin\JsonRpcServerBundle\Mcp\McpResultFormatter:
 *             alias: App\Rpc\MyFormatter
 */
interface McpResultFormatter
{
    /**
     * @return list<array<string, mixed>> MCP content array
     */
    public function format(mixed $result, McpFormat $format, MethodMetadata $method): array;
}
