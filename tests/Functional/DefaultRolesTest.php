<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

/**
 * Covers json_rpc_server.security.default_roles + public_prefixes + public_methods.
 *
 * Resolution precedence (mirrors MethodCompilerPass::resolveEffectiveRoles):
 *   attribute roles  >  public_methods  >  public_prefixes  >  prefix_roles  >  default_roles
 *
 * prefix_roles coverage lives in PrefixRolesTest.
 */
final class DefaultRolesTest extends KernelTestCase
{
    public function testBareMethodStaysPublicWhenDefaultRolesEmpty(): void
    {
        // Baseline: no default_roles configured → historical behavior, anonymous passes.
        $payload = $this->call('{"jsonrpc":"2.0","method":"secured.bare","id":1}', rpcConfig: []);
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testBareMethodDeniedForAnonymousWhenDefaultRolesSet(): void
    {
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            rpcConfig: ['security' => ['default_roles' => ['ROLE_USER']]],
        );
        $this->assertSame(-32001, $payload['error']['code']);
    }

    public function testBareMethodAllowedWhenAnonymousHoldsDefaultRole(): void
    {
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            roles: ['ROLE_USER'],
            rpcConfig: ['security' => ['default_roles' => ['ROLE_USER']]],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testPublicPrefixOverridesDefaultRoles(): void
    {
        // public.ping matches public_prefixes — should bypass default_roles.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"public.ping","id":1}',
            rpcConfig: [
                'security' => [
                    'default_roles' => ['ROLE_USER'],
                    'public_prefixes' => ['public.'],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testPublicMethodOverridesDefaultRoles(): void
    {
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.ping","id":1}',
            rpcConfig: [
                'security' => [
                    'default_roles' => ['ROLE_USER'],
                    'public_methods' => ['secured.ping'],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testExplicitAttributeRolesWinOverDefault(): void
    {
        // test.roleAnd hard-codes ROLE_A + ROLE_B (rolesMatch: All). default_roles must not interfere.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"test.roleAnd","id":1}',
            roles: ['ROLE_USER'],
            rpcConfig: ['security' => ['default_roles' => ['ROLE_USER']]],
        );
        $this->assertSame(-32001, $payload['error']['code']);
    }

    /**
     * @param list<string> $roles
     * @param array<string, mixed> $rpcConfig
     *
     * @return array<string, mixed>
     */
    private function call(string $body, array $roles = [], array $rpcConfig = []): array
    {
        $kernel = $this->boot($rpcConfig);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ([] !== $roles) {
            $server['HTTP_X_TEST_ROLES'] = implode(',', $roles);
        }
        $request = Request::create('/rpc', 'POST', server: $server, content: $body);
        $response = $kernel->handle($request);

        return $this->decodeJsonResponse($response);
    }
}
