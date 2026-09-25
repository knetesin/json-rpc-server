<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;

#[Rpc\Method('guard.groupRename')]
#[Rpc\Mcp(description: 'Rename a group.')]
#[RequiresGroup(GroupPermission::Edit)]
final class GroupRename
{
    /** @return array{groupId: int, name: string} */
    public function __invoke(int $groupId, string $name): array
    {
        return ['groupId' => $groupId, 'name' => $name];
    }
}
