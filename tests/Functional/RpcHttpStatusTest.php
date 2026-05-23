<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class RpcHttpStatusTest extends KernelTestCase
{
    public function testDefaultKeeps200ForApplicationErrors(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"does.not.exist","id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(-32601, $this->decodeJsonResponse($response)['error']['code']);
    }

    public function testEnabledMapsMethodNotFoundTo404(): void
    {
        $kernel = $this->boot(['http_status' => ['enabled' => true]]);
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"does.not.exist","id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(-32601, $this->decodeJsonResponse($response)['error']['code']);
    }

    public function testEnabledBatchUsesWorstStatus(): void
    {
        $kernel = $this->boot(['http_status' => ['enabled' => true]]);
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '[
                {"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},
                {"jsonrpc":"2.0","method":"does.not.exist","id":2}
            ]',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(2, $payload);
        /** @var list<array<string, mixed>> $items */
        $items = array_values($payload);
        $this->assertArrayHasKey('result', $items[0]);
        $this->assertSame(-32601, $items[1]['error']['code']);
    }

    public function testParserOversizeReturns413WithoutHttpStatusFlag(): void
    {
        $kernel = $this->boot(['max_request_size' => 32]);
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1,"pad":"'.str_repeat('x', 64).'"}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(413, $response->getStatusCode());
    }
}
