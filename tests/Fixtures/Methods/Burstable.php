<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\RateLimitPolicy;
use JsonRpcServer\Attribute\RateLimitScope;

#[Rpc\Method('test.burstable')]
#[Rpc\RateLimit(
    limit: 3,
    intervalSec: 60,
    scope: RateLimitScope::GlobalScope,
    policy: RateLimitPolicy::TokenBucket,
)]
final class Burstable
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
