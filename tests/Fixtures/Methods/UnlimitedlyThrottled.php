<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitPolicy;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

/**
 * Documents intent (this endpoint is rate-sensitive) but disables enforcement
 * via policy=NoLimit. Used to verify the checker short-circuits NoLimit.
 */
#[Rpc\Method('test.no_limit')]
#[Rpc\RateLimit(
    limit: 1,
    intervalSec: 1,
    scope: RateLimitScope::GlobalScope,
    policy: RateLimitPolicy::NoLimit,
)]
final class UnlimitedlyThrottled
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
