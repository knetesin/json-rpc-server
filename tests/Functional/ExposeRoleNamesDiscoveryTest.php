<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * security.expose_role_names reaches the discovery surfaces: /mcp/tools and
 * the OpenRPC document.
 */
final class ExposeRoleNamesDiscoveryTest extends KernelTestCase
{
    public function testMcpToolsListRolesByDefault(): void
    {
        $tool = $this->mcpTool(['mcp' => ['expose_all' => true]], 'test.admin');

        $this->assertSame(['ROLE_ADMIN'], $tool['roles']);
    }

    public function testMcpToolsOmitRolesWhenRoleNamesAreHidden(): void
    {
        $tool = $this->mcpTool(['mcp' => ['expose_all' => true], 'security' => ['expose_role_names' => false]], 'test.admin');

        $this->assertArrayNotHasKey('roles', $tool);
    }

    public function testOpenRpcListsRolesByDefault(): void
    {
        $method = $this->openRpcMethod([], 'test.admin');

        $this->assertSame(['ROLE_ADMIN'], $method['x-rpc-roles']);
        $this->assertArrayHasKey('x-rpc-roles-match', $method);
    }

    public function testOpenRpcOmitsRolesWhenRoleNamesAreHidden(): void
    {
        $method = $this->openRpcMethod(['security' => ['expose_role_names' => false]], 'test.admin');

        $this->assertArrayNotHasKey('x-rpc-roles', $method);
        $this->assertArrayNotHasKey('x-rpc-roles-match', $method);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function mcpTool(array $config, string $name): array
    {
        $kernel = $this->boot($config);
        $payload = $this->decodeJsonResponse($kernel->handle(Request::create('/mcp/tools', 'GET')));
        $tools = array_column($payload['tools'], null, 'name');
        $this->assertArrayHasKey($name, $tools);

        return $tools[$name];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function openRpcMethod(array $config, string $name): array
    {
        $kernel = $this->boot($config);
        $tester = new CommandTester((new Application($kernel))->find('debug:rpc'));
        $tester->execute(['--openrpc' => true, '--title' => 't', '--api-version' => '1']);
        $tester->assertCommandIsSuccessful();

        $doc = json_decode($tester->getDisplay(), true, 64, \JSON_THROW_ON_ERROR);
        $methods = array_column($doc['methods'], null, 'name');
        $this->assertArrayHasKey($name, $methods);

        return $methods[$name];
    }
}
