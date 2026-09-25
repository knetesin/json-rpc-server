<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Keyed
{
    /**
     * @param array<array-key, mixed> $map
     */
    public function __construct(public array $map)
    {
    }
}
