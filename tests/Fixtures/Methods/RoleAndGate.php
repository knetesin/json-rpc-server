<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\RoleMatch;

#[Rpc\Method('test.roleAnd', roles: ['ROLE_A', 'ROLE_B'], rolesMatch: RoleMatch::All)]
final class RoleAndGate
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
