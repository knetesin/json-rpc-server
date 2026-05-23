<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Symfony\Component\HttpFoundation\Request;

#[Rpc\Method('test.http_probe')]
final class HttpRequestProbe
{
    /** @return array<string, mixed> */
    public function __invoke(Request $request): array
    {
        return [
            'path' => $request->getPathInfo(),
            'header' => $request->headers->get('X-Probe'),
        ];
    }
}
