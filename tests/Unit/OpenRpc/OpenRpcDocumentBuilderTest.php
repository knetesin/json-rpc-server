<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\OpenRpc;

use Knetesin\JsonRpcServerBundle\Mcp\JsonSchemaBuilder;
use Knetesin\JsonRpcServerBundle\OpenRpc\OpenRpcDocumentBuilder;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler\ProfilerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class OpenRpcDocumentBuilderTest extends TestCase
{
    public function testRolesArePublishedByDefault(): void
    {
        $method = $this->onlyMethod($this->builder());

        $this->assertSame(['ROLE_ADMIN'], $method['x-rpc-roles'] ?? null);
        $this->assertSame('any', $method['x-rpc-roles-match'] ?? null);
    }

    public function testRolesAreOmittedWhenRoleNamesAreHidden(): void
    {
        $method = $this->onlyMethod($this->builder(exposeRoleNames: false));

        $this->assertSame('admin.stats', $method['name']);
        $this->assertArrayNotHasKey('x-rpc-roles', $method);
        $this->assertArrayNotHasKey('x-rpc-roles-match', $method);
    }

    private function builder(bool $exposeRoleNames = true): OpenRpcDocumentBuilder
    {
        $methods = new MethodRegistry(
            ['admin.stats' => ProfilerTestHelper::rawMethod('admin.stats', roles: ['ROLE_ADMIN'])],
            $this->createStub(ContainerInterface::class),
        );

        return new OpenRpcDocumentBuilder($methods, new JsonSchemaBuilder(), exposeRoleNames: $exposeRoleNames);
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyMethod(OpenRpcDocumentBuilder $builder): array
    {
        $methods = $builder->build('Test', '1.0')['methods'];
        $this->assertIsArray($methods);
        $this->assertCount(1, $methods);
        $this->assertIsArray($methods[0]);

        return $methods[0];
    }
}
