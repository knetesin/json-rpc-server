<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitPolicy;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

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
