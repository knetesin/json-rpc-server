<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ParamErrors;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('test.paramErrors.shape')]
final class ShapeCreate
{
    public function __invoke(ShapeRequest $shape): string
    {
        return $shape->kind;
    }
}
