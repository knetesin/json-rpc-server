<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

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
