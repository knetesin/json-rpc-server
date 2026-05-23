<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Dispatcher;

use Knetesin\JsonRpcServerBundle\Attribute\RoleMatch;
use Knetesin\JsonRpcServerBundle\Dispatcher\Dispatcher;
use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RoleAuthorizationTest extends TestCase
{
    public function testPublicMethodSkipsAuthorization(): void
    {
        $this->expectNotToPerformAssertions();
        $this->checkRoles($this->meta(roles: []), grantedRoles: []);
    }

    public function testAnyAllowsWhenOneRoleGranted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->checkRoles(
            $this->meta(roles: ['ROLE_ADMIN', 'ROLE_USER'], rolesMatch: RoleMatch::Any),
            grantedRoles: ['ROLE_USER'],
        );
    }

    public function testAnyDeniesWhenNoRoleGranted(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('One of the following roles is required');

        $this->checkRoles(
            $this->meta(roles: ['ROLE_ADMIN', 'ROLE_USER'], rolesMatch: RoleMatch::Any),
            grantedRoles: [],
        );
    }

    public function testAllRequiresEveryRole(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('All of the following roles are required');

        $this->checkRoles(
            $this->meta(roles: ['ROLE_A', 'ROLE_B'], rolesMatch: RoleMatch::All),
            grantedRoles: ['ROLE_A'],
        );
    }

    public function testAllAllowsWhenEveryRoleGranted(): void
    {
        $this->expectNotToPerformAssertions();
        $this->checkRoles(
            $this->meta(roles: ['ROLE_A', 'ROLE_B'], rolesMatch: RoleMatch::All),
            grantedRoles: ['ROLE_A', 'ROLE_B'],
        );
    }

    public function testRoleGatedMethodWithoutAuthorizationChecker(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('symfony/security-bundle');

        $this->checkRoles($this->meta(roles: ['ROLE_ADMIN']), grantedRoles: [], withAuthChecker: false);
    }

    /**
     * @param list<string> $grantedRoles
     */
    private function checkRoles(
        MethodMetadata $meta,
        array $grantedRoles,
        bool $withAuthChecker = true,
    ): void {
        if ($withAuthChecker) {
            // createStub (not createMock) — we configure return values but don't
            // care about call counts; PHPUnit 11 flags unconfigured mock objects.
            $auth = $this->createStub(AuthorizationCheckerInterface::class);
            $auth->method('isGranted')->willReturnCallback(
                static fn (string $role): bool => \in_array($role, $grantedRoles, true),
            );
        } else {
            $auth = null;
        }

        $dispatcher = (new \ReflectionClass(Dispatcher::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Dispatcher::class, 'authChecker'))->setValue($dispatcher, $auth);
        // checkRoles reads exposeRoleNames to decide whether to include role
        // names in the exception message; bypassing the constructor leaves
        // it uninitialized, so seed it here.
        (new \ReflectionProperty(Dispatcher::class, 'exposeRoleNames'))->setValue($dispatcher, true);

        $method = new \ReflectionMethod(Dispatcher::class, 'checkRoles');
        $method->setAccessible(true);
        $method->invoke($dispatcher, $meta);
    }

    /**
     * @param list<string> $roles
     */
    private function meta(array $roles, RoleMatch $rolesMatch = RoleMatch::Any): MethodMetadata
    {
        return new MethodMetadata(
            name: 'stub',
            serviceClass: 'Stub',
            roles: $roles,
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
            rolesMatch: $rolesMatch,
        );
    }
}
