<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('auth.logout')]
final class AuthLogout
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['action' => 'logout'];
    }
}
