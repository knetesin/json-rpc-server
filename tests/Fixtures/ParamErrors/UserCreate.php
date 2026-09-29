<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ParamErrors;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Dto\UserRequest;

/** Denormalization errors of a DTO with scalar, enum and nested-DTO fields. */
#[Rpc\Method('test.paramErrors.user')]
final class UserCreate
{
    public function __invoke(UserRequest $req): string
    {
        return $req->name;
    }
}
