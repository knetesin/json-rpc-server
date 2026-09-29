<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Mcp;

use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\Mcp\McpToolRegistry;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler\ProfilerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class McpToolRegistryTest extends TestCase
{
    public function testRolesArePublishedByDefault(): void
    {
        $tools = $this->registry()->getTools();

        $this->assertCount(1, $tools);
        $this->assertSame(['ROLE_ADMIN'], $tools[0]['roles'] ?? null);
    }

    public function testRolesAreOmittedWhenRoleNamesAreHidden(): void
    {
        $tools = $this->registry(exposeRoleNames: false)->getTools();

        $this->assertCount(1, $tools);
        $this->assertSame('admin.stats', $tools[0]['name']);
        $this->assertArrayNotHasKey('roles', $tools[0]);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
    }

    private function registry(bool $exposeRoleNames = true): McpToolRegistry
    {
        $methods = new MethodRegistry(
            ['admin.stats' => ProfilerTestHelper::rawMethod('admin.stats', mcp: true, roles: ['ROLE_ADMIN'])],
            $this->createStub(ContainerInterface::class),
        );

        return new McpToolRegistry($methods, new McpToolFilter(false, [], [], []), exposeRoleNames: $exposeRoleNames);
    }
}
