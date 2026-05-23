<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class RoleAccessTest extends KernelTestCase
{
    public function testRoleOrDeniedForAnonymous(): void
    {
        $payload = $this->call('{"jsonrpc":"2.0","method":"test.roleOr","id":1}');
        $this->assertSame(-32001, $payload['error']['code']);
        $this->assertStringContainsString('One of the following roles is required', $payload['error']['message']);
    }

    public function testRoleOrAllowedWithSingleMatchingRole(): void
    {
        $payload = $this->callAs(['ROLE_USER'], '{"jsonrpc":"2.0","method":"test.roleOr","id":1}');
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testRoleAndDeniedWithOnlyOneRole(): void
    {
        $payload = $this->callAs(['ROLE_A'], '{"jsonrpc":"2.0","method":"test.roleAnd","id":1}');
        $this->assertSame(-32001, $payload['error']['code']);
        $this->assertStringContainsString('All of the following roles are required', $payload['error']['message']);
    }

    public function testRoleAndAllowedWithAllRoles(): void
    {
        $payload = $this->callAs(['ROLE_A', 'ROLE_B'], '{"jsonrpc":"2.0","method":"test.roleAnd","id":1}');
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testGlobalConfigRolesMatchAll(): void
    {
        $payload = $this->callAs(
            ['ROLE_A'],
            '{"jsonrpc":"2.0","method":"test.roleConfigAll","id":1}',
            ['security' => ['roles_match' => 'all']],
        );
        $this->assertSame(-32001, $payload['error']['code']);

        $payload = $this->callAs(
            ['ROLE_A', 'ROLE_B'],
            '{"jsonrpc":"2.0","method":"test.roleConfigAll","id":1}',
            ['security' => ['roles_match' => 'all']],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    /**
     * @param list<string> $roles
     * @param array<string, mixed> $rpcConfig
     *
     * @return array<string, mixed>
     */
    private function callAs(array $roles, string $body, array $rpcConfig = []): array
    {
        $kernel = $this->boot($rpcConfig);
        $request = Request::create('/rpc', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TEST_ROLES' => implode(',', $roles),
        ], content: $body);
        $response = $kernel->handle($request);

        return $this->decodeJsonResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $body): array
    {
        $kernel = $this->boot();
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);

        return $this->decodeJsonResponse($response);
    }
}
