<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Methods;

use JsonRpcServer\Attribute as Rpc;

/**
 * Holds state in an instance property (NOT static). Under shared services
 * the value would survive across calls; with setShared(false) every dispatch
 * must see the constructor default — `n: 1` on every invocation.
 */
#[Rpc\Method('test.stateful_probe')]
final class StatefulProbe
{
    private int $n = 0;

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['n' => ++$this->n];
    }
}
