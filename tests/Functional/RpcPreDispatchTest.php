<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\Batch\FanoutDecision;
use Knetesin\JsonRpcServerBundle\Event\BatchDispatchedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\StreamRejection\ProbeFeed;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Items rejected by RpcController before dispatch: invalid batch items,
 * streaming methods and per-method MaxRequestSize violations.
 */
final class RpcPreDispatchTest extends KernelTestCase
{
    private const array WITH_STREAM_PROBES = ['StreamRejection'];

    protected function setUp(): void
    {
        parent::setUp();
        ProbeFeed::$invoked = false;
    }

    public function testInvalidBatchItemsGetOwnErrorsAndValidItemsRun(): void
    {
        $kernel = $this->boot();
        $response = $this->post($kernel, '[
            {"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":"1"},
            {"jsonrpc":"2.0","method":"math.add","params":{"a":7,"b":2}},
            {"foo":"boo"},
            {"jsonrpc":"2.0","method":"math.add","params":{"a":4,"b":5},"id":"2"},
            1
        ]');

        $this->assertSame(200, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertCount(4, $payload);

        $results = array_values(array_filter($payload, static fn (array $r): bool => \array_key_exists('result', $r)));
        $errors = array_values(array_filter($payload, static fn (array $r): bool => \array_key_exists('error', $r)));
        $this->assertSame([['sum' => 3], ['sum' => 9]], array_column($results, 'result'));
        $this->assertCount(2, $errors);
        foreach ($errors as $error) {
            $this->assertSame('2.0', $error['jsonrpc']);
            $this->assertSame(-32600, $error['error']['code']);
            $this->assertNull($error['id']);
        }
    }

    public function testBatchOfScalarsYieldsOneErrorPerItem(): void
    {
        $kernel = $this->boot();
        $response = $this->post($kernel, '[1,2,3]');

        $this->assertSame(200, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertCount(3, $payload);
        foreach ($payload as $error) {
            $this->assertSame(-32600, $error['error']['code']);
            $this->assertNull($error['id']);
        }
    }

    public function testInvalidBatchItemsFollowStatusMappingWhenEnabled(): void
    {
        $kernel = $this->boot(['http_status' => ['enabled' => true]]);
        $response = $this->post($kernel, '[{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},{"foo":"boo"}]');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertCount(2, $this->decodeJsonResponse($response));
    }

    public function testInvalidSingleRequestAndEmptyBatchStillAnswerWithOneError(): void
    {
        $kernel = $this->boot();

        foreach (['{"jsonrpc":"1.0","method":"math.add","id":1}', '[]'] as $body) {
            $payload = $this->decodeJsonResponse($this->post($kernel, $body));
            $this->assertArrayNotHasKey(0, $payload, $body);
            $this->assertSame(-32600, $payload['error']['code']);
            $this->assertNull($payload['id']);
        }
    }

    public function testInvalidBatchItemsNeverReachTheDispatcherAndCountInBatchSize(): void
    {
        $kernel = $this->boot();
        $started = new \ArrayObject();
        $batches = new \ArrayObject();
        $bus = $this->bus($kernel);
        $bus->addListener(MethodInvocationStartedEvent::class, static function (MethodInvocationStartedEvent $e) use ($started): void {
            $started->append($e->method->name);
        });
        $bus->addListener(BatchDispatchedEvent::class, static function (BatchDispatchedEvent $e) use ($batches): void {
            $batches->append($e->batchSize);
        });

        $this->post($kernel, '[{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},{"method":"math.add","id":2},"x"]');

        $this->assertSame(['math.add'], $started->getArrayCopy());
        $this->assertSame([3], $batches->getArrayCopy());
    }

    public function testItemWithoutJsonFormIsRejectedAloneInBatch(): void
    {
        // 1e400 decodes to INF, which has no JSON form: the item can be neither
        // measured against the size limit nor sent as a sub-call body.
        $kernel = $this->boot();
        $response = $this->post($kernel, '[
            {"jsonrpc":"2.0","method":"math.add","params":{"a":1e400,"b":2},"id":1},
            {"jsonrpc":"2.0","method":"math.add","params":{"a":4,"b":5},"id":2}
        ]');

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column($this->decodeJsonResponse($response), null, 'id');
        $this->assertSame(-32602, $byId[1]['error']['code']);
        $this->assertSame(['sum' => 9], $byId[2]['result']);
    }

    public function testInvalidSelfUrlFromEnvOnlyDisablesFanOut(): void
    {
        $_SERVER['RPC_SELF_URL'] = '/rpc';
        try {
            $kernel = $this->boot(['parallel_batch' => ['enabled' => true, 'self_url' => '%env(RPC_SELF_URL)%', 'min_batch_size' => 2, 'budget_store' => 'null']]);
            $decisions = new \ArrayObject();
            $this->bus($kernel)->addListener(BatchDispatchedEvent::class, static function (BatchDispatchedEvent $e) use ($decisions): void {
                $decisions->append($e->decision);
            });

            $single = $this->post($kernel, '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}');
            $this->assertSame(['sum' => 3], $this->decodeJsonResponse($single)['result']);

            $batch = $this->post($kernel, '[
                {"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},
                {"jsonrpc":"2.0","method":"math.add","params":{"a":4,"b":5},"id":2}
            ]');
            $this->assertSame([['sum' => 3], ['sum' => 9]], array_column($this->decodeJsonResponse($batch), 'result'));
            $this->assertSame([FanoutDecision::SequentialDisabled, FanoutDecision::SequentialDisabled], $decisions->getArrayCopy());
        } finally {
            unset($_SERVER['RPC_SELF_URL']);
        }
    }

    public function testRejectedItemsAreNotHandedToTheParallelExecutor(): void
    {
        // Only one item survives pre-dispatch, so the batch is below min_batch_size.
        $kernel = $this->boot(
            ['parallel_batch' => ['enabled' => true, 'self_url' => 'http://localhost/rpc', 'min_batch_size' => 2, 'budget_store' => 'null']],
            self::WITH_STREAM_PROBES,
        );
        $decisions = new \ArrayObject();
        $this->bus($kernel)->addListener(BatchDispatchedEvent::class, static function (BatchDispatchedEvent $e) use ($decisions): void {
            $decisions->append($e->decision);
        });

        $this->post($kernel, '[
            {"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},
            {"jsonrpc":"2.0","method":"stream_probe.feed","id":2},
            {"foo":"boo"}
        ]');

        $this->assertSame([FanoutDecision::SequentialTooSmall], $decisions->getArrayCopy());
        $this->assertFalse(ProbeFeed::$invoked);
    }

    public function testStreamingMethodIsRejectedOnRpcWithoutRunning(): void
    {
        $kernel = $this->boot([], self::WITH_STREAM_PROBES);
        $response = $this->post($kernel, '{"jsonrpc":"2.0","method":"stream_probe.feed","id":1}');

        $this->assertSame(200, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(1, $payload['id']);
        $this->assertSame(-32600, $payload['error']['code']);
        $this->assertStringContainsString('streaming endpoint', $payload['error']['message']);
        $this->assertFalse(ProbeFeed::$invoked);
    }

    public function testStreamingNotificationIsDroppedWithoutRunning(): void
    {
        $kernel = $this->boot([], self::WITH_STREAM_PROBES);
        $response = $this->post($kernel, '{"jsonrpc":"2.0","method":"stream_probe.feed"}');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse(ProbeFeed::$invoked);
    }

    public function testStreamingMethodInBatchIsRejectedPerItem(): void
    {
        $kernel = $this->boot([], self::WITH_STREAM_PROBES);
        $response = $this->post($kernel, '[
            {"jsonrpc":"2.0","method":"stream_probe.feed","id":1},
            {"jsonrpc":"2.0","method":"stream_probe.feed"},
            {"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":2}
        ]');

        $payload = $this->decodeJsonResponse($response);
        $this->assertCount(2, $payload);
        $byId = array_column($payload, null, 'id');
        $this->assertSame(-32600, $byId[1]['error']['code']);
        $this->assertSame(['sum' => 3], $byId[2]['result']);
        $this->assertFalse(ProbeFeed::$invoked);
    }

    public function testSmallBatchItemIsMeasuredByItsOwnSize(): void
    {
        // file.upload is capped at 4096 bytes; the whole batch is far larger,
        // but the upload item itself is small.
        $kernel = $this->boot(['max_request_size' => 1 << 20]);
        $body = \sprintf(
            '[{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"},"id":1},'
            .'{"jsonrpc":"2.0","method":"test.params_echo","params":{"pad":"%s"},"id":2}]',
            str_repeat('a', 100),
            str_repeat('x', 10_000),
        );
        $response = $this->post($kernel, $body);

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column($this->decodeJsonResponse($response), null, 'id');
        $this->assertSame(100, $byId[1]['result']['received']);
        $this->assertArrayHasKey('result', $byId[2]);
    }

    public function testOversizedBatchItemIsPerItemErrorWithout413(): void
    {
        $kernel = $this->boot(['max_request_size' => 1 << 20]);
        $body = \sprintf(
            '[{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"},"id":1},'
            .'{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":2}]',
            str_repeat('a', 8192),
        );
        $response = $this->post($kernel, $body);

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column($this->decodeJsonResponse($response), null, 'id');
        $this->assertSame(-32600, $byId[1]['error']['code']);
        $this->assertStringContainsString('limit: 4096', $byId[1]['error']['message']);
        $this->assertSame(['sum' => 3], $byId[2]['result']);
    }

    public function testOversizedBatchItemFollowsStatusMappingWhenEnabled(): void
    {
        $kernel = $this->boot(['max_request_size' => 1 << 20, 'http_status' => ['enabled' => true]]);
        $body = \sprintf(
            '[{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"%s"},"id":1},'
            .'{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":2}]',
            str_repeat('a', 8192),
        );

        $this->assertSame(400, $this->post($kernel, $body)->getStatusCode());
    }

    public function testBatchOfOneOversizedItemIsNot413(): void
    {
        // Even a one-item batch is a batch: the oversized item is a per-item -32600.
        $kernel = $this->boot(['max_request_size' => 1 << 20]);
        $response = $this->post($kernel, '[{"jsonrpc":"2.0","method":"file.upload","params":{"payload":"'.str_repeat('a', 8192).'"},"id":1}]');

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column($this->decodeJsonResponse($response), null, 'id');
        $this->assertSame(-32600, $byId[1]['error']['code']);
    }

    private function post(KernelInterface $kernel, string $body): Response
    {
        return $kernel->handle(Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body));
    }

    private function bus(KernelInterface $kernel): EventDispatcherInterface
    {
        $bus = $kernel->getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $bus);

        return $bus;
    }
}
