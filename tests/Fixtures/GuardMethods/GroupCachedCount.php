<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;

#[Rpc\Method('guard.groupCachedCount')]
#[Rpc\Cache(ttl: 60)]
#[RequiresGroup(GroupPermission::View)]
final class GroupCachedCount
{
    /** @return array{groupId: int} */
    public function __invoke(CountedGroupRef $ref): array
    {
        return ['groupId' => $ref->groupId];
    }
}
