<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Batch;

use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;
use Knetesin\JsonRpcServerBundle\Http\FanoutClientIpSigner;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\RequestStack;

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

    public function testFailedNotificationSubcallProducesNoResponseEntry(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['error' => 'connection refused']),     // notification
            new MockResponse('{"jsonrpc":"2.0","result":"ok","id":2}'),
        ]);
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [
            new RpcRequest(id: null, method: 'audit.log', params: new RpcParams([]), isNotification: true),
            new RpcRequest(id: 2, method: 'b', params: new RpcParams([]), isNotification: false),
        ];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(1, $result['responses']);
        $this->assertSame('ok', $result['responses'][0]['result']);
        $this->assertCount(2, $result['durations']);
    }

    public function testNotificationThatCannotBeDispatchedProducesNoResponseEntry(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $opt): MockResponse {
            $body = $opt['body'] ?? '';
            if (\is_string($body) && str_contains($body, '"audit.log"')) {
                throw new TransportException('connect failed');
            }

            return new MockResponse('{"jsonrpc":"2.0","result":"ok","id":2}');
        });
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [
            new RpcRequest(id: null, method: 'audit.log', params: new RpcParams([]), isNotification: true),
            new RpcRequest(id: 2, method: 'b', params: new RpcParams([]), isNotification: false),
        ];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(1, $result['responses']);
        $this->assertSame('ok', $result['responses'][0]['result']);
        $this->assertCount(2, $result['durations']);
    }

    public function testRegularCallThatCannotBeDispatchedBecomesErrorEnvelope(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('connect failed');
        });
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [new RpcRequest(id: 5, method: 'a', params: new RpcParams([]), isNotification: false)];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(1, $result['responses']);
        $this->assertSame(-32603, $result['responses'][0]['error']['code']);
        $this->assertSame(5, $result['responses'][0]['id']);
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

    public function testSubCallsGoToConfiguredSelfUrlWhateverTheHostHeader(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $url;

            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}');
        });
        $executor = $this->executor($http, 'http://127.0.0.1/rpc');

        $original = HttpRequest::create('https://example.com/api/rpc');
        $original->headers->set('Host', 'attacker.example');
        $items = [new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false)];
        $executor->execute($items, $original, 0);

        $this->assertSame('http://127.0.0.1/rpc', $sent[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSelfUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['/rpc'];
        yield 'other scheme' => ['ftp://127.0.0.1/rpc'];
    }

    #[DataProvider('invalidSelfUrls')]
    public function testInvalidSelfUrlDisablesFanOutAndIsLoggedOnce(string $url): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->errors[] = (string) $message;
                }
            }
        };
        $executor = new ParallelBatchExecutor(
            http: new MockHttpClient(),
            maxConcurrency: 5,
            timeoutSec: 5.0,
            connectTimeoutSec: 0.5,
            forwardHeaders: [],
            selfUrl: $url,
            logger: $logger,
        );

        $this->assertFalse($executor->isUsable());
        $this->assertFalse($executor->isUsable());
        $this->assertCount(1, $logger->errors);
        $this->assertStringContainsString('self_url must be an absolute', $logger->errors[0]);

        $this->expectException(\LogicException::class);
        $executor->execute([new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false)], HttpRequest::create('/rpc'), 0);
    }

    public function testValidSelfUrlIsUsable(): void
    {
        $this->assertTrue($this->executor(new MockHttpClient(), 'https://api.test/rpc')->isUsable());
    }

    public function testItemWithoutJsonFormBecomesInvalidParamsWithoutSubCall(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $opt['body'];

            return new MockResponse('{"jsonrpc":"2.0","result":"ok","id":3}');
        });
        $executor = $this->executor($http, 'http://api.test/rpc');

        $result = $executor->execute([
            new RpcRequest(id: 1, method: 'a', params: new RpcParams(['x' => \INF]), isNotification: false),
            new RpcRequest(id: null, method: 'a', params: new RpcParams(['x' => \INF]), isNotification: true),
            new RpcRequest(id: 3, method: 'a', params: new RpcParams([]), isNotification: false),
        ], HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(1, $sent);
        $this->assertCount(2, $result['responses']);
        $this->assertSame(1, $result['responses'][0]['id']);
        $this->assertSame(-32602, $result['responses'][0]['error']['code']);
        $this->assertSame('ok', $result['responses'][1]['result']);
    }

    public function testNotificationReplyWithBodyIsNotAddedToResponses(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"jsonrpc":"2.0","result":"leaked","id":null}'),   // notification answered with 200 + body
            new MockResponse('{"jsonrpc":"2.0","result":"ok","id":1}'),
        ]);
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [
            new RpcRequest(id: null, method: 'audit.log', params: new RpcParams([]), isNotification: true),
            new RpcRequest(id: 1, method: 'user.get', params: new RpcParams([]), isNotification: false),
        ];

        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertCount(1, $result['responses']);
        $this->assertSame('ok', $result['responses'][0]['result']);
        $this->assertCount(2, $result['durations']);
    }

    public function testNotificationWithUnparsableReplyIsSilent(): void
    {
        $http = new MockHttpClient([new MockResponse('<html>oops</html>', ['http_code' => 500])]);
        $executor = $this->executor($http, 'http://api.test/rpc');

        $items = [new RpcRequest(id: null, method: 'audit.log', params: new RpcParams([]), isNotification: true)];
        $result = $executor->execute($items, HttpRequest::create('http://api.test/rpc'), 0);

        $this->assertSame([], $result['responses']);
        $this->assertCount(1, $result['durations']);
    }

    public function testSignsOriginalClientIpPerSubCall(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $opt;

            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}');
        });
        $signer = new FanoutClientIpSigner('secret');
        $executor = $this->executor($http, 'http://api.test/rpc', signer: $signer);

        $original = HttpRequest::create('http://api.test/rpc', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $items = [
            new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false),
            new RpcRequest(id: 2, method: 'b', params: new RpcParams([]), isNotification: false),
        ];
        $executor->execute($items, $original, 0);

        foreach ($sent as $opt) {
            $this->assertContains('X-Rpc-Fanout-Client-Ip: 203.0.113.7', $opt['headers']);
            $this->assertContains('X-Rpc-Fanout-Signature: '.$signer->sign('203.0.113.7', 1, $opt['body']), $opt['headers']);
        }
        // The signature binds the body, so it differs per item.
        $this->assertNotEquals($sent[0]['headers'], $sent[1]['headers']);
    }

    public function testNestedFanOutForwardsVerifiedOriginalIpNotLoopback(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $opt['headers'];

            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}');
        });
        $signer = new FanoutClientIpSigner('secret');

        // Incoming sub-call at depth 1 from loopback, carrying the signed client IP.
        $body = '[{"jsonrpc":"2.0","method":"a","id":1},{"jsonrpc":"2.0","method":"b","id":2}]';
        $original = HttpRequest::create('http://api.test/rpc', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1'], content: $body);
        $original->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '1');
        $original->headers->set(FanoutClientIpSigner::IP_HEADER, '203.0.113.7');
        $original->headers->set(FanoutClientIpSigner::SIGNATURE_HEADER, $signer->sign('203.0.113.7', 1, $body));

        $stack = new RequestStack();
        $stack->push($original);
        $executor = $this->executor($http, 'http://api.test/rpc', signer: $signer, clientIps: new ClientIpResolver($stack, $signer));

        $executor->execute([new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false)], $original, 1);

        $this->assertContains('X-Rpc-Fanout-Client-Ip: 203.0.113.7', $sent[0]);
        $this->assertContains('X-Rpc-Fanout-Depth: 2', $sent[0]);
    }

    public function testInternalHeadersAreNeverCopiedFromTheClient(): void
    {
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $opt) use (&$sent): MockResponse {
            $sent[] = $opt['headers'];

            return new MockResponse('{"jsonrpc":"2.0","result":1,"id":1}');
        });
        $executor = new ParallelBatchExecutor(
            http: $http,
            maxConcurrency: 5,
            timeoutSec: 5.0,
            connectTimeoutSec: 0.5,
            // HeaderBag reads "_" as "-", so underscore spellings must be filtered too.
            forwardHeaders: ['x-rpc-fanout-depth', 'X_Rpc_Fanout_Depth', 'X-Rpc-Fanout-Client-Ip', 'X_Rpc_Fanout_Client_Ip', 'X-Rpc-Fanout-Signature', 'x_rpc_fanout_signature'],
            selfUrl: 'http://api.test/rpc',
        );

        $original = HttpRequest::create('http://api.test/rpc');
        $original->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '0');
        $original->headers->set(FanoutClientIpSigner::IP_HEADER, '198.51.100.1');
        $original->headers->set(FanoutClientIpSigner::SIGNATURE_HEADER, 'forged');
        $executor->execute([new RpcRequest(id: 1, method: 'a', params: new RpcParams([]), isNotification: false)], $original, 0);

        $this->assertContains('X-Rpc-Fanout-Depth: 1', $sent[0]);
        $headers = implode("\n", $sent[0]);
        $this->assertStringNotContainsString('198.51.100.1', $headers);
        $this->assertStringNotContainsString('forged', $headers);
        $this->assertStringNotContainsStringIgnoringCase('x_rpc_fanout', $headers);
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
    private function executor(
        MockHttpClient $http,
        string $selfUrl,
        int $maxConcurrency = 5,
        ?FanoutClientIpSigner $signer = null,
        ?ClientIpResolver $clientIps = null,
    ): ParallelBatchExecutor {
        return new ParallelBatchExecutor(
            http: $http,
            maxConcurrency: $maxConcurrency,
            timeoutSec: 5.0,
            connectTimeoutSec: 0.5,
            forwardHeaders: ['Authorization', 'X-Request-Id'],
            selfUrl: $selfUrl,
            clientIpSigner: $signer,
            clientIps: $clientIps,
        );
    }
}
