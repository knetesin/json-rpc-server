<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Marker
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $label,
        public readonly array $tags = [],
    ) {
    }
}
