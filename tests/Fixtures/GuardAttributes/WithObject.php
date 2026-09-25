<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class WithObject
{
    public function __construct(public object $value)
    {
    }
}
