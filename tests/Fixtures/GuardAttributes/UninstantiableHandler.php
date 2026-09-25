<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardAttributes;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('attrs.uninstantiable')]
#[Strict(-1)]
final class UninstantiableHandler
{
    public function __invoke(): void
    {
    }
}
