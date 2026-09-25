<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Params;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** JSON key differs from the PHP variable name and the int carries no constraints. */
#[Rpc\Method('test.params.groupEcho')]
final class GroupEcho
{
    public function __invoke(
        #[Rpc\Param('group_id')]
        int $groupId,
    ): int {
        return $groupId;
    }
}
