<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RoleMatch;

#[Rpc\Method('test.roleOr', roles: ['ROLE_ADMIN', 'ROLE_USER'], rolesMatch: RoleMatch::Any)]
final class RoleOrGate
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
