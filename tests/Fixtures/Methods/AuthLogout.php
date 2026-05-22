<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('auth.logout')]
final class AuthLogout
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['action' => 'logout'];
    }
}
