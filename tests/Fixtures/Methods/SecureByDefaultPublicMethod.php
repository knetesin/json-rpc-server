<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

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
