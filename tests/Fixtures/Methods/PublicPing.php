<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

// No roles argument => public method (anonymous access allowed by the dispatcher).
#[Rpc\Method('test.public')]
final class PublicPing
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
