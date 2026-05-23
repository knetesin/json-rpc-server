<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Exception\AccessDeniedException;
use Knetesin\JsonRpcServerBundle\Exception\NotFoundException;

#[Rpc\Method('test.boom')]
final class Boom
{
    public function __invoke(BoomRequest $req): never
    {
        match ($req->kind) {
            'access' => throw new AccessDeniedException('nope'),
            'access_custom' => throw new AccessDeniedException('quota', -32050),
            'not_found' => throw new NotFoundException('gone'),
            'unhandled' => throw new \RuntimeException('boom'),
            default => throw new \InvalidArgumentException('unknown kind: '.$req->kind),
        };
    }
}
