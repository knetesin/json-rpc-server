<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('auth.getSession')]
final class AuthGetSession
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['session' => 'auth-session-data'];
    }
}
