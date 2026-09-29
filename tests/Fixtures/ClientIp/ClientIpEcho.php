<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\ClientIp;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;

#[Rpc\Method('clientip.echo')]
final class ClientIpEcho
{
    public function __construct(private readonly ClientIpResolver $clientIps)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return ['ip' => $this->clientIps->clientIp()];
    }
}
