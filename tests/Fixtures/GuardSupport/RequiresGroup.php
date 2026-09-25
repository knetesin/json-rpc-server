<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport;

/**
 * Handler marker read by GroupGuard: the caller needs $permission on the group
 * whose id is in the argument (or DTO property) named $on.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class RequiresGroup
{
    public function __construct(
        public GroupPermission $permission,
        public string $on = 'groupId',
    ) {
    }
}
