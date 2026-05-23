<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;

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
