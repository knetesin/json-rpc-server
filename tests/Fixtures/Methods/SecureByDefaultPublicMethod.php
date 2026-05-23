<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Exact name match for public_methods: ["secured.ping"]. */
#[Rpc\Method('secured.ping')]
final class SecureByDefaultPublicMethod
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
