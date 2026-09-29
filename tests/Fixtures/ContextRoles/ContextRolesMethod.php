<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ContextRoles;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Context\Context;

#[Rpc\Method('test.contextRoles')]
final class ContextRolesMethod
{
    /** @return array{roles: list<string>, hasUser: bool, hasAdmin: bool} */
    public function __invoke(Context $ctx): array
    {
        return [
            'roles' => $ctx->roles,
            'hasUser' => $ctx->hasRole('ROLE_USER'),
            'hasAdmin' => $ctx->hasRole('ROLE_ADMIN'),
        ];
    }
}
