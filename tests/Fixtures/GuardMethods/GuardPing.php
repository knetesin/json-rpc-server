<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

#[Rpc\Method('guard.ping')]
final class GuardPing
{
    public function __invoke(): string
    {
        return 'pong';
    }
}
