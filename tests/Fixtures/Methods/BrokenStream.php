<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;
use Knetesin\JsonRpcServerBundle\Exception\NotFoundException;

#[Rpc\Method('stream.broken')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
final class BrokenStream
{
    public function __invoke(): \Generator
    {
        yield ['n' => 1];
        yield ['n' => 2];
        throw new NotFoundException('row gone');
    }
}
