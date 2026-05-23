<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** No roles attribute — picks up json_rpc_server.security.default_roles when set. */
#[Rpc\Method('secured.bare')]
final class SecureByDefaultBare
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
