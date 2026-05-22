<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;
use JsonRpcServer\Attribute\StreamFormat;

#[Rpc\Method('stream.tick')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
final class Tick
{
    public function __invoke(TickRequest $req): \Generator
    {
        for ($i = 1; $i <= $req->count; ++$i) {
            yield ['n' => $i];
        }
    }
}
