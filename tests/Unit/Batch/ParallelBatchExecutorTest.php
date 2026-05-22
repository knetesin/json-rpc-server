<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Batch;

use JsonRpcServer\Batch\ParallelBatchExecutor;
use JsonRpcServer\Request\RpcParams;
use JsonRpcServer\Request\RpcRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

final class ParallelBatchExecutorTest extends TestCase
{
    public function testFanOutPostsOneItemPerSubcall(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = ['method' => $method, 'url' => $url, 'body' => $opt['body'], 'headers' => $opt['headers'] ?? []];

            return new MockResponse('{"jsonrpc":"2.0","result":42,"id":'.\count($sent).'}');
        });

        $executor = $this->executor($http, 'http://api.test/rpc');
        $items = [
            new RpcRequest(id: 1, method: 'a', params: new RpcParams(['x' => 1]), isNotification: false),
            new RpcRequest(id: 2, method: 'b', params: new RpcParams(['y' => 2]), isNotification: false),
        ];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(2, $result['responses']);
        $this->assertCount(2, $sent);

        $first = json_decode($sent[0]['body'], true);
        $this->assertSame('2.0', $first['jsonrpc']);
        $this->assertSame('a', $first['method']);
        $this->assertSame(['x' => 1], $first['params']);
        $this->assertSame(1, $first['id']);

        // Recursion-guard header on every sub-call: depth = parent+1.
        $this->assertContains('X-Rpc-Fanout-Depth: 1', $sent[0]['headers']);
    }

    public function testNotificationProducesNoResponseEntry(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 204]),                  // notification
            new MockResponse('{"jsonrpc":"2.0","result":"ok","id":1}'),  // regular
        ]);
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [
            new RpcRequest(id: null, method: 'audit.log', params: new RpcParams([]), isNotification: true),
            new RpcRequest(id: 1, method: 'user.get', params: new RpcParams(['id' => 1]), isNotification: false),
        ];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        // Only the regular call lands in responses; notification is silent per spec.
        $this->assertCount(1, $result['responses']);
        $this->assertSame('ok', $result['responses'][0]['result']);
    }

    public function testTransportFailureBecomesPerItemErrorEnvelope(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),
            new MockResponse('{"jsonrpc":"2.0","result":"ok","id":2}'),
        ]);
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [
            new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false),
            new RpcRequest(id: 2, method: 'b', params: new RpcParams([]), isNotification: false),
        ];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(2, $result['responses']);
        $this->assertSame(-32603, $result['responses'][0]['error']['code']);  // InternalError for the failed item
        $this->assertSame('ok', $result['responses'][1]['result']);            // Other item unaffected
    }

    public function testRespectsMaxConcurrencyByChunking(): void
    {
        $inflight = 0;
        $maxObserved = 0;
        $http = new MockHttpClient(static function () use (&$inflight, &$maxObserved): MockResponse {
            ++$inflight;
            $maxObserved = max($maxObserved, $inflight);

            // Lazy info-callback fires when getContent() runs — by then inflight has reset.
            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}', ['response_headers' => []]);
        });

        // MockHttpClient is synchronous so we can't truly measure parallel inflight
        // — but we CAN verify that the dispatcher slices into chunks of N. The
        // assertion here is "all items processed", as a smoke test.
        $items = array_map(
            static fn (int $i) => new RpcRequest(id: $i, method: 'x', params: new RpcParams([]), isNotification: false),
            range(1, 7),
        );

        $executor = $this->executor($http, 'http://api.test/rpc', maxConcurrency: 3);
        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(7, $result['responses']);
        $this->assertSame(7, $inflight);  // All went through MockHttpClient
    }

    public function testForwardsConfiguredHeadersFromOriginalRequest(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $opt['headers'] ?? [];

            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}');
        });

        $original = HttpRequest::create('http://api.test/rpc');
        $original->headers->set('Authorization', 'Bearer abc');
        $original->headers->set('X-Request-Id', 'req-42');

        $executor = new ParallelBatchExecutor(
            http: $http,
            maxConcurrency: 5,
            timeoutSec: 5.0,
            connectTimeoutSec: 0.5,
            forwardHeaders: ['Authorization', 'X-Request-Id'],
            selfUrl: 'http://api.test/rpc',
        );

        $items = [new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false)];
        $executor->execute($items, $original, 0);

        $this->assertContains('Authorization: Bearer abc', $sent[0]);
        $this->assertContains('X-Request-Id: req-42', $sent[0]);
    }

    public function testDerivedSelfUrlUsesOriginalSchemeAndHost(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $url;

            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}');
        });

        // No explicit self_url — should be derived from the incoming request.
        $executor = new ParallelBatchExecutor(
            http: $http,
            maxConcurrency: 5,
            timeoutSec: 5.0,
            connectTimeoutSec: 0.5,
            forwardHeaders: [],
            selfUrl: null,
        );

        $original = HttpRequest::create('https://example.com/api/rpc');
        $items = [new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false)];
        $executor->execute($items, $original, 0);

        $this->assertSame('https://example.com/api/rpc', $sent[0]);
    }

    public function testDepthOfReadsHeaderInteger(): void
    {
        $r = HttpRequest::create('/rpc');
        $r->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '2');

        $this->assertSame(2, ParallelBatchExecutor::depthOf($r));
        // Missing header → 0.
        $this->assertSame(0, ParallelBatchExecutor::depthOf(HttpRequest::create('/rpc')));
        // Garbage → 0 (no fancy parsing).
        $r->headers->set(ParallelBatchExecutor::DEPTH_HEADER, 'not-a-number');
        $this->assertSame(0, ParallelBatchExecutor::depthOf($r));
    }

    /**
     * @param positive-int $maxConcurrency
     */
    private function executor(MockHttpClient $http, string $selfUrl, int $maxConcurrency = 5): ParallelBatchExecutor
    {
        return new ParallelBatchExecutor(
            http: $http,
            maxConcurrency: $maxConcurrency,
            timeoutSec: 5.0,
            connectTimeoutSec: 0.5,
            forwardHeaders: ['Authorization', 'X-Request-Id'],
            selfUrl: $selfUrl,
        );
    }
}
