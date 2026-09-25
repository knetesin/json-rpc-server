<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Encoding;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** A float without a fractional part: JSON_PRESERVE_ZERO_FRACTION decides between "1" and "1.0". */
#[Rpc\Method('test.encoding.wholeFloat')]
final class WholeFloat
{
    public function __invoke(): float
    {
        return 1.0;
    }
}
