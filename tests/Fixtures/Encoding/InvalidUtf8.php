<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Encoding;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Result that json_encode() cannot represent: a lone continuation byte is not UTF-8. */
#[Rpc\Method('test.encoding.invalidUtf8')]
final class InvalidUtf8
{
    public function __invoke(): string
    {
        return "bad \xB1 byte";
    }
}
