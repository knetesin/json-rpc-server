<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

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
