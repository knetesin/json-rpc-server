<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;

#[Rpc\Method('guard.groupCachedStats')]
#[Rpc\Cache(ttl: 60)]
#[RequiresGroup(GroupPermission::View)]
final class GroupCachedStats
{
    /** Handler invocations; reset by tests. */
    public static int $invocations = 0;

    /** @return array{groupId: int, members: int} */
    public function __invoke(int $groupId): array
    {
        ++self::$invocations;

        return ['groupId' => $groupId, 'members' => 3];
    }
}
