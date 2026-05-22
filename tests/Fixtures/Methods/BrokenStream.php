<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\StreamFormat;
use JsonRpcServer\Exception\NotFoundException;

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
