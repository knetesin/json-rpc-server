<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Percent;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Roles and cache pool/tags resolve %parameter% placeholders, unlike free text. */
#[Rpc\Method('test.percentResolved', roles: ['%kernel.environment%'])]
#[Rpc\Cache(ttl: 60, pool: '%kernel.environment%', tags: ['%kernel.environment%'])]
final class PercentResolved
{
    public function __invoke(): string
    {
        return 'ok';
    }
}
