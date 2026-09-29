<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\StreamRejection;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;

#[Rpc\Method('stream_probe.admin', roles: ['ROLE_ADMIN'])]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
final class AdminFeed
{
    /**
     * @return \Generator<array<string, int>>
     */
    public function __invoke(): \Generator
    {
        yield ['n' => 1];
    }
}
