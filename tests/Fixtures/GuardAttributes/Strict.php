<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

/** Rejects a negative level only when instantiated, like any validating attribute. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Strict
{
    public function __construct(public int $level)
    {
        if ($level < 0) {
            throw new \InvalidArgumentException('level must not be negative');
        }
    }
}
