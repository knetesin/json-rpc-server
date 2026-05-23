<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamEndpointTest extends KernelTestCase
{
    public function testNdjsonStreamYieldsRowPerLine(): void
    {
        $kernel = $this->boot();

        $request = Request::create(
            '/rpc/stream',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"stream.tick","params":{"count":3},"id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));

        $body = $this->captureStreamBody($response);

        $lines = array_values(array_filter(explode("\n", $body), static fn (string $l): bool => '' !== $l));
        $this->assertCount(3, $lines);
        $this->assertSame(['n' => 1], json_decode($lines[0], true));
        $this->assertSame(['n' => 2], json_decode($lines[1], true));
        $this->assertSame(['n' => 3], json_decode($lines[2], true));
    }

    public function testNonStreamingMethodReturns400(): void
    {
        $kernel = $this->boot();

        $request = Request::create(
            '/rpc/stream',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertSame(1, $payload['id']);
    }

    public function testMethodNotFoundReturns404WithEnvelope(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc/stream',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"does.not.exist","id":7}',
        );
        $response = $kernel->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(-32601, $payload['error']['code']);
        $this->assertSame(7, $payload['id']);
    }

    public function testMidStreamErrorAppendsNdjsonErrorFrame(): void
    {
        $kernel = $this->boot();
        $request = Request::create(
            '/rpc/stream',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"stream.broken","id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $body = $this->captureStreamBody($response);

        $lines = array_values(array_filter(explode("\n", $body), static fn (string $l): bool => '' !== $l));
        $this->assertCount(3, $lines, 'two data lines plus one error frame');
        $this->assertSame(['n' => 1], json_decode($lines[0], true));
        $this->assertSame(['n' => 2], json_decode($lines[1], true));

        $errorFrame = json_decode($lines[2], true);
        $this->assertArrayHasKey('error', $errorFrame);
        $this->assertSame(-32002, $errorFrame['error']['code']);
        $this->assertSame('row gone', $errorFrame['error']['message']);
    }
}
