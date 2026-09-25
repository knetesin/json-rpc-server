<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

/** Counts constructions, i.e. how often arguments were resolved. */
final class CountedGroupRef
{
    /** Reset by tests. */
    public static int $constructed = 0;

    public function __construct(
        public readonly int $groupId,
    ) {
        ++self::$constructed;
    }
}
