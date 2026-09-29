<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\StreamRejection;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;
use Knetesin\JsonRpcServerBundle\Exception\InternalErrorException;

/**
 * Throws an RpcException from `__invoke()` itself, before any row exists.
 */
#[Rpc\Method('stream_probe.failing')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
final class FailingFeed
{
    /**
     * @return iterable<mixed>
     */
    public function __invoke(): iterable
    {
        throw new InternalErrorException('Backend unavailable');
    }
}
