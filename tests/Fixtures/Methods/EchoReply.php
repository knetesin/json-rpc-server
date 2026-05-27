<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

final readonly class EchoReply
{
    public function __construct(
        public string $pong,
        public int $length,
    ) {
    }
}
