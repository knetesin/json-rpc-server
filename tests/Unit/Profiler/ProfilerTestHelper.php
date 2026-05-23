<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler;

use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\Profiler\JsonRpcDataCollector;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Psr\Container\ContainerInterface;

final class ProfilerTestHelper
{
    /**
     * @param array<string, array<string, mixed>> $rawMethods
     */
    public static function collector(array $rawMethods = []): JsonRpcDataCollector
    {
        $handlers = new class implements ContainerInterface {
            public function get(string $id): object
            {
                return new \stdClass();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        return new JsonRpcDataCollector(
            new MethodRegistry($rawMethods, $handlers),
            new McpToolFilter(false, [], [], []),
        );
    }

    /**
     * @param list<string> $roles
     *
     * @return array<string, mixed>
     */
    public static function rawMethod(string $name, bool $mcp = false, bool $streaming = false, bool $deprecated = false, array $roles = []): array
    {
        return [
            'name' => $name,
            'serviceClass' => 'App\\Stub',
            'roles' => $roles,
            'description' => null,
            'parameters' => [],
            'returnType' => null,
            'isStreaming' => $streaming,
            'streamFormat' => null,
            'hasMcpAttribute' => $mcp,
            'mcpEnabled' => true,
            'deprecated' => $deprecated ? 'use other' : null,
        ];
    }
}
