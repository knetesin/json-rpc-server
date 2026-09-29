<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\PercentName;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** A method name the container would read as a %parameter% placeholder; the build must reject it. */
#[Rpc\Method('test.%kernel.environment%')]
final class PercentInName
{
    public function __invoke(): string
    {
        return 'unreachable';
    }
}
