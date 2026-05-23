<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Name matches public_prefixes when set to ["public."] — stays anonymous. */
#[Rpc\Method('public.ping')]
final class SecureByDefaultPublicPrefix
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
