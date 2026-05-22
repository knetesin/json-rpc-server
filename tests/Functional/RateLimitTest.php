<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;

final class RateLimitTest extends KernelTestCase
{
    public function testThirdCallExceedsTheLimitAndReturnsRetryAfter(): void
    {
        $kernel = $this->boot();

        $first = $this->call($kernel);
        $second = $this->call($kernel);
        $third = $this->call($kernel);

        $this->assertSame(['ok' => true], $first['result']);
        $this->assertSame(['ok' => true], $second['result']);

        $this->assertSame(-32003, $third['error']['code']);
        $this->assertStringContainsString('Rate limit', $third['error']['message']);
        $this->assertArrayHasKey('retryAfter', $third['error']['data']);
        $this->assertIsInt($third['error']['data']['retryAfter']);
    }

    public function testRateLimitSetsRetryAfterHttpHeader(): void
    {
        $kernel = $this->boot();
        $this->call($kernel);
        $this->call($kernel);

        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.throttled","id":1}',
        );
        $response = $kernel->handle($request);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
    }

    public function testTokenBucketAllowsBurstUpToBucketSize(): void
    {
        $kernel = $this->boot();

        $payload = '{"jsonrpc":"2.0","method":"test.burstable","id":1}';
        $first = $this->callMethod($kernel, $payload);
        $second = $this->callMethod($kernel, $payload);
        $third = $this->callMethod($kernel, $payload);
        $fourth = $this->callMethod($kernel, $payload);

        // bucket size = 3 → first 3 calls drain it, 4th must throttle
        $this->assertSame(['ok' => true], $first['result']);
        $this->assertSame(['ok' => true], $second['result']);
        $this->assertSame(['ok' => true], $third['result']);
        $this->assertSame(-32003, $fourth['error']['code']);
    }

    public function testNoLimitPolicyDisablesEnforcement(): void
    {
        $kernel = $this->boot();

        $payload = '{"jsonrpc":"2.0","method":"test.no_limit","id":1}';

        // limit=1/intervalSec=1 would normally throttle on call 2, but
        // policy: NoLimit short-circuits the checker entirely.
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->callMethod($kernel, $payload);
            $this->assertSame(['ok' => true], $result['result'], "call $i should succeed");
        }
    }

    public function testRequestTooLargeIsRejected(): void
    {
        $kernel = $this->boot(['max_request_size' => 32]);

        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}',
        );
        $response = $kernel->handle($request);
        $payload = $this->decodeJsonResponse($response);

        // JSON-RPC 2.0 is HTTP-status-agnostic — /rpc always returns 200 and
        // signals failure through the `error` object in the body.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertStringContainsString('too large', $payload['error']['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(\Symfony\Component\HttpKernel\KernelInterface $kernel): array
    {
        return $this->callMethod($kernel, '{"jsonrpc":"2.0","method":"test.throttled","id":1}');
    }

    /**
     * @return array<string, mixed>
     */
    private function callMethod(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $body): array
    {
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $response = $kernel->handle($request);

        return $this->decodeJsonResponse($response);
    }
}
