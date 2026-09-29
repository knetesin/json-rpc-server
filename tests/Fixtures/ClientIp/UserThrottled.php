<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ClientIp;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

#[Rpc\Method('clientip.user_throttled')]
#[Rpc\RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::User)]
final class UserThrottled
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
