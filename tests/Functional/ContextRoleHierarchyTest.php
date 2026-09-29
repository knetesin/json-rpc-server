<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Context::$roles / hasRole() follow security.role_hierarchy, like isGranted().
 */
final class ContextRoleHierarchyTest extends KernelTestCase
{
    public function testHierarchyIsExpandedIntoContextRoles(): void
    {
        $result = $this->callAs(['ROLE_ADMIN'], ['ROLE_ADMIN' => ['ROLE_USER']]);

        $this->assertSame(['roles' => ['ROLE_ADMIN', 'ROLE_USER'], 'hasUser' => true, 'hasAdmin' => true], $result);
    }

    public function testRawRolesWithoutHierarchy(): void
    {
        $result = $this->callAs(['ROLE_ADMIN'], []);

        $this->assertSame(['roles' => ['ROLE_ADMIN'], 'hasUser' => false, 'hasAdmin' => true], $result);
    }

    /**
     * @param list<string> $roles
     * @param array<string, list<string>> $hierarchy
     *
     * @return array<string, mixed>
     */
    private function callAs(array $roles, array $hierarchy): array
    {
        // TestKernel has no role_hierarchy config; set the parameter SecurityBundle
        // feeds into security.role_hierarchy before placeholders are resolved.
        $pass = new class($hierarchy) implements CompilerPassInterface {
            /** @param array<string, list<string>> $hierarchy */
            public function __construct(private readonly array $hierarchy)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                $container->setParameter('security.role_hierarchy.roles', $this->hierarchy);
            }
        };

        $kernel = $this->boot(
            extraFixtures: ['ContextRoles'],
            compilerPasses: [['pass' => $pass, 'type' => PassConfig::TYPE_BEFORE_OPTIMIZATION, 'priority' => 0]],
        );
        $request = Request::create('/rpc', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TEST_ROLES' => implode(',', $roles),
        ], content: '{"jsonrpc":"2.0","method":"test.contextRoles","id":1}');
        $payload = $this->decodeJsonResponse($kernel->handle($request));
        $this->assertArrayHasKey('result', $payload, $this->jsonEncode($payload));
        $this->assertIsArray($payload['result']);

        return $payload['result'];
    }
}
