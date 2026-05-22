<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

#[Rpc\Method('test.internalReport')]
#[Rpc\Mcp(enabled: false)]
final class InternalReport
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['kind' => 'internal'];
    }
}
