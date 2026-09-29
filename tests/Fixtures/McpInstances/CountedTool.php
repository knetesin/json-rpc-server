<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\McpInstances;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/**
 * Counts constructions; the handler service is non-shared, so every
 * container lookup builds a new instance.
 */
#[Rpc\Method('mcp_count.plain')]
#[Rpc\Mcp]
final class CountedTool
{
    public static int $constructed = 0;

    public function __construct()
    {
        ++self::$constructed;
    }

    /**
     * @return array<string, bool>
     */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
