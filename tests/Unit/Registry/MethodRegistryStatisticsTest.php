<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Registry;

use Knetesin\JsonRpcServerBundle\Mcp\McpToolFilter;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler\ProfilerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class MethodRegistryStatisticsTest extends TestCase
{
    public function testStatisticsAggregateRawMetadata(): void
    {
        $handlers = $this->createStub(ContainerInterface::class);
        $handlers->method('get')->willReturn(new \stdClass());

        $registry = new MethodRegistry([
            'public.ping' => ProfilerTestHelper::rawMethod('public.ping', mcp: true),
            'stream.logs' => ProfilerTestHelper::rawMethod('stream.logs', streaming: true),
            'admin.purge' => ProfilerTestHelper::rawMethod('admin.purge', roles: ['ROLE_ADMIN'], deprecated: true),
        ], $handlers);

        $stats = $registry->statistics(new McpToolFilter(false, [], [], []));

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['streaming']);
        $this->assertSame(1, $stats['deprecated']);
        $this->assertSame(1, $stats['secured']);
        $this->assertSame(1, $stats['mcp_exposed']);
    }
}
