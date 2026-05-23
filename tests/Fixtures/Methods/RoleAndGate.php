<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RoleMatch;

#[Rpc\Method('test.roleAnd', roles: ['ROLE_A', 'ROLE_B'], rolesMatch: RoleMatch::All)]
final class RoleAndGate
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
