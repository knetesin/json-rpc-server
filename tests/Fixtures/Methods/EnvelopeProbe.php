<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;

#[Rpc\Method('test.envelope_probe')]
final class EnvelopeProbe
{
    /** @return array<string, mixed> */
    public function __invoke(RpcRequest $envelope): array
    {
        return [
            'id' => $envelope->id,
            'method' => $envelope->method,
            'isNotification' => $envelope->isNotification,
            'params' => $envelope->params->all(),
        ];
    }
}
