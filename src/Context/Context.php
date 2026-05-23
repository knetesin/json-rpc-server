<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Context;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class Context
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $methodName,
        public string $requestId,
        public ?UserInterface $user,
        public array $roles,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }
}
