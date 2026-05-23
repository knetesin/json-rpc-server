<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Uses global json_rpc_server.security.roles_match: all (no per-method rolesMatch). */
#[Rpc\Method('test.roleConfigAll', roles: ['ROLE_A', 'ROLE_B'])]
final class RoleConfigAllGate
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
