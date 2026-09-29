<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\StreamRejection;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;

#[Rpc\Method('stream_probe.limited')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
#[Rpc\RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::GlobalScope)]
final class LimitedFeed
{
    /**
     * @return \Generator<array<string, int>>
     */
    public function __invoke(): \Generator
    {
        yield ['n' => 1];
    }
}
