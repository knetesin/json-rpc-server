<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

final readonly class GroupRef
{
    public function __construct(
        public int $groupId,
    ) {
    }
}
