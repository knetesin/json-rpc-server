<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Pre-stream failures on /rpc/stream map the error code to an HTTP status
 * regardless of http_status.enabled.
 */
final class StreamStatusTest extends KernelTestCase
{
    private const array WITH_STREAM_PROBES = ['StreamRejection'];

    public function testRateLimitedStreamReturns429WithRetryAfter(): void
    {
        $kernel = $this->boot([], self::WITH_STREAM_PROBES);

        $this->assertInstanceOf(StreamedResponse::class, $this->stream($kernel, 'stream_probe.limited'));

        $response = $this->stream($kernel, 'stream_probe.limited');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
        $this->assertSame(-32003, $this->decodeJsonResponse($response)['error']['code']);
    }

    public function testAccessDeniedStreamReturns403(): void
    {
        $kernel = $this->boot([], self::WITH_STREAM_PROBES);
        $response = $this->stream($kernel, 'stream_probe.admin');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(-32001, $this->decodeJsonResponse($response)['error']['code']);
        $this->assertFalse($response->headers->has('Retry-After'));
    }

    public function testRpcInternalErrorBeforeStreamReturns500(): void
    {
        $kernel = $this->boot([], self::WITH_STREAM_PROBES);
        $response = $this->stream($kernel, 'stream_probe.failing');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(-32603, $this->decodeJsonResponse($response)['error']['code']);
    }

    public function testInvalidParamsBeforeStreamReturns400(): void
    {
        $kernel = $this->boot();
        $response = $this->stream($kernel, 'stream.tick', ['count' => 'many']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(-32602, $this->decodeJsonResponse($response)['error']['code']);
    }

    public function testOversizedBodyReturns413(): void
    {
        // The parser cap is the largest of the global and per-method limits (file.upload: 4096).
        $kernel = $this->boot(['max_request_size' => 64]);
        $response = $this->stream($kernel, 'stream.tick', ['count' => 1, 'pad' => str_repeat('x', 5000)]);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame(-32600, $this->decodeJsonResponse($response)['error']['code']);
    }

    public function testStatusMappingIsIndependentOfHttpStatusFlag(): void
    {
        $kernel = $this->boot(['http_status' => ['enabled' => false]], self::WITH_STREAM_PROBES);

        $this->assertSame(403, $this->stream($kernel, 'stream_probe.admin')->getStatusCode());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stream(KernelInterface $kernel, string $method, array $params = []): Response
    {
        $envelope = ['jsonrpc' => '2.0', 'method' => $method, 'id' => 1];
        if ([] !== $params) {
            $envelope['params'] = $params;
        }

        return $kernel->handle(Request::create('/rpc/stream', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $this->jsonEncode($envelope)));
    }
}
