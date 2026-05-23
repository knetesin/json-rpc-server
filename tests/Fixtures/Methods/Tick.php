<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;

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
