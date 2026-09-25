<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;

/**
 * `__invoke` is not a generator itself, so $invoked proves whether the
 * handler ran at all (a generator body would only start on iteration).
 */
#[Rpc\Method('guard.groupFeed')]
#[Rpc\Stream]
#[RequiresGroup(GroupPermission::View)]
final class GroupFeed
{
    /** Reset by tests. */
    public static bool $invoked = false;

    /** @return \Generator<int, array{groupId: int, n: int}> */
    public function __invoke(int $groupId): \Generator
    {
        self::$invoked = true;

        return $this->rows($groupId);
    }

    /** @return \Generator<int, array{groupId: int, n: int}> */
    private function rows(int $groupId): \Generator
    {
        yield ['groupId' => $groupId, 'n' => 1];
        yield ['groupId' => $groupId, 'n' => 2];
    }
}
