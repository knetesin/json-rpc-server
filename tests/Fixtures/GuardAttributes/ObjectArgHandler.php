<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('attrs.objectArg')]
#[WithObject(new \ArrayObject())]
final class ObjectArgHandler
{
    public function __invoke(): void
    {
    }
}
