<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;

/** DTO parameter: GroupGuard reads the group id from the DTO property. */
#[Rpc\Method('guard.groupShow')]
#[RequiresGroup(GroupPermission::View)]
final class GroupShow
{
    /** @return array{groupId: int} */
    public function __invoke(GroupRef $ref): array
    {
        return ['groupId' => $ref->groupId];
    }
}
