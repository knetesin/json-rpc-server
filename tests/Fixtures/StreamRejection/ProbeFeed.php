<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\StreamRejection;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Attribute\StreamFormat;

/**
 * Records whether the handler body ran. `__invoke()` is not a generator
 * itself, so `$invoked` flips as soon as the dispatcher calls it.
 */
#[Rpc\Method('stream_probe.feed')]
#[Rpc\Stream(format: StreamFormat::Ndjson)]
#[Rpc\Mcp]
final class ProbeFeed
{
    public static bool $invoked = false;

    /**
     * @return iterable<array<string, int>>
     */
    public function __invoke(): iterable
    {
        self::$invoked = true;

        return $this->rows();
    }

    /**
     * @return \Generator<array<string, int>>
     */
    private function rows(): \Generator
    {
        yield ['n' => 1];
    }
}
