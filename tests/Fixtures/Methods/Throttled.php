<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\RateLimitScope;

#[Rpc\Method('test.throttled')]
#[Rpc\RateLimit(limit: 2, intervalSec: 60, scope: RateLimitScope::GlobalScope)]
final class Throttled
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
