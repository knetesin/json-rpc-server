<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

/**
 * Covers json_rpc_server.security.prefix_roles.
 *
 * Resolution precedence (mirrors MethodCompilerPass::resolveEffectiveRoles):
 *   attribute roles  >  public_methods  >  public_prefixes  >  prefix_roles  >  default_roles
 *
 * Longest matching prefix wins when multiple prefix_roles entries apply.
 */
final class PrefixRolesTest extends KernelTestCase
{
    public function testPrefixRolesAppliedToBareMethod(): void
    {
        // secured.bare carries no roles attribute → "secured." prefix applies ROLE_PREFIX.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            roles: ['ROLE_PREFIX'],
            rpcConfig: ['security' => ['prefix_roles' => ['secured.' => ['ROLE_PREFIX']]]],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testPrefixRolesDenyWhenAnonymousLacksRole(): void
    {
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            rpcConfig: ['security' => ['prefix_roles' => ['secured.' => ['ROLE_PREFIX']]]],
        );
        $this->assertSame(-32001, $payload['error']['code']);
    }

    public function testLongestPrefixWins(): void
    {
        // Both "secured." and "secured.b" match "secured.bare" — the longer one wins.
        // Configure short prefix with a role the caller LACKS and long prefix with one they HOLD;
        // success proves the long prefix's roles were chosen.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            roles: ['ROLE_LONG'],
            rpcConfig: [
                'security' => [
                    'prefix_roles' => [
                        'secured.' => ['ROLE_SHORT'],
                        'secured.b' => ['ROLE_LONG'],
                    ],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testExplicitAttributeRolesWinOverPrefixRoles(): void
    {
        // test.roleAnd hard-codes ROLE_A + ROLE_B (rolesMatch: All) → prefix_roles must NOT override.
        // Caller holds the prefix role but not the attribute roles → denied.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"test.roleAnd","id":1}',
            roles: ['ROLE_PREFIX'],
            rpcConfig: ['security' => ['prefix_roles' => ['test.' => ['ROLE_PREFIX']]]],
        );
        $this->assertSame(-32001, $payload['error']['code']);
    }

    public function testPublicMethodsWinOverPrefixRoles(): void
    {
        // secured.bare in public_methods → stays anonymous despite prefix_roles match.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            rpcConfig: [
                'security' => [
                    'public_methods' => ['secured.bare'],
                    'prefix_roles' => ['secured.' => ['ROLE_PREFIX']],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testPublicPrefixesWinOverPrefixRoles(): void
    {
        // "secured." in public_prefixes → public, even though prefix_roles has the same prefix.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            rpcConfig: [
                'security' => [
                    'public_prefixes' => ['secured.'],
                    'prefix_roles' => ['secured.' => ['ROLE_PREFIX']],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testPrefixRolesBeatDefaultRoles(): void
    {
        // default_roles would demand ROLE_USER; prefix_roles narrows it to ROLE_PREFIX for "secured.*".
        // Caller holds ROLE_PREFIX but NOT ROLE_USER → success proves default_roles did not apply.
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            roles: ['ROLE_PREFIX'],
            rpcConfig: [
                'security' => [
                    'default_roles' => ['ROLE_USER'],
                    'prefix_roles' => ['secured.' => ['ROLE_PREFIX']],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
    }

    public function testNonMatchingMethodFallsBackToDefaultRoles(): void
    {
        // "secured.bare" does NOT match "admin." prefix → falls through to default_roles (ROLE_USER).
        $payload = $this->call(
            '{"jsonrpc":"2.0","method":"secured.bare","id":1}',
            roles: ['ROLE_USER'],
            rpcConfig: [
                'security' => [
                    'default_roles' => ['ROLE_USER'],
                    'prefix_roles' => ['admin.' => ['ROLE_ADMIN']],
                ],
            ],
        );
        $this->assertSame(['ok' => true], $payload['result']);
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
