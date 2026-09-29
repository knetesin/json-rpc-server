<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Reads the current user from TokenStorage (security-core only).
 * When symfony/security-bundle is not installed the storage is absent and
 * every call behaves as anonymous.
 */
final class SecurityUserResolver
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function getUser(): ?UserInterface
    {
        $token = $this->tokenStorage?->getToken();
        $user = $token?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * Identifier of the current user, or "anon" for guests. Not collision-safe:
     * a real user may be named "anon" — branch on {@see getUser()} === null to
     * tell guests apart.
     */
    public function getUserIdentifier(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'anon';
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values($this->tokenStorage?->getToken()?->getRoleNames() ?? []);
    }
}
