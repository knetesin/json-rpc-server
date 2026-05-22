<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\RoleMatch;

#[Rpc\Method('test.roleOr', roles: ['ROLE_ADMIN', 'ROLE_USER'], rolesMatch: RoleMatch::Any)]
final class RoleOrGate
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
