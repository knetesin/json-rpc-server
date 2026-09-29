<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Context;

use Knetesin\JsonRpcServerBundle\Context\ContextFactory;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ContextFactoryTest extends TestCase
{
    public function testRequestIdHeaderIsRead(): void
    {
        $stack = new RequestStack();
        $stack->push($this->requestWithHeader('X-Request-Id', 'caller-supplied-42'));

        $ctx = $this->factory($stack)->create('user.update');

        $this->assertSame('caller-supplied-42', $ctx->requestId);
    }

    public function testCustomRequestIdHeaderIsHonored(): void
    {
        $stack = new RequestStack();
        $stack->push($this->requestWithHeader('X-Trace-Id', 'trace-99'));

        $ctx = $this->factory($stack, header: 'X-Trace-Id')->create('user.update');

        $this->assertSame('trace-99', $ctx->requestId);
    }

    public function testEmptyHeaderConfigSkipsHeaderLookup(): void
    {
        $stack = new RequestStack();
        $stack->push($this->requestWithHeader('X-Request-Id', 'ignored-because-disabled'));

        $ctx = $this->factory($stack, header: '')->create('user.update');

        // Lookup disabled → fresh random id, not the header value.
        $this->assertNotSame('ignored-because-disabled', $ctx->requestId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $ctx->requestId);
    }

    public function testRequestIdIsStableAcrossBatchItems(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());

        $factory = $this->factory($stack);
        $first = $factory->create('a.first');
        $second = $factory->create('b.second');

        // Critical: every item in a JSON-RPC batch shares one requestId.
        $this->assertSame($first->requestId, $second->requestId);
    }

    public function testRolesAreExpandedThroughRoleHierarchy(): void
    {
        $factory = new ContextFactory(
            new RequestStack(),
            $this->usersWithRoles(['ROLE_ADMIN']),
            roleHierarchy: new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]),
        );

        $ctx = $factory->create('user.update');

        $this->assertSame(['ROLE_ADMIN', 'ROLE_USER'], $ctx->roles);
        $this->assertTrue($ctx->hasRole('ROLE_USER'));
    }

    public function testRawRolesWithoutRoleHierarchy(): void
    {
        $factory = new ContextFactory(new RequestStack(), $this->usersWithRoles(['ROLE_ADMIN']));

        $ctx = $factory->create('user.update');

        $this->assertSame(['ROLE_ADMIN'], $ctx->roles);
        $this->assertFalse($ctx->hasRole('ROLE_USER'));
    }

    /**
     * @param list<string> $roles
     */
    private function usersWithRoles(array $roles): SecurityUserResolver
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, $roles), 'main', $roles));

        return new SecurityUserResolver($storage);
    }

    private function factory(RequestStack $stack, string $header = 'X-Request-Id'): ContextFactory
    {
        // SecurityUserResolver is final; instantiating it with no TokenStorage
        // makes it behave as anonymous, which is what these tests want.
        $users = new SecurityUserResolver(null);

        return new ContextFactory($stack, $users, requestIdHeader: $header);
    }

    private function requestWithHeader(string $name, string $value): Request
    {
        $request = new Request();
        $request->headers->set($name, $value);

        return $request;
    }
}
