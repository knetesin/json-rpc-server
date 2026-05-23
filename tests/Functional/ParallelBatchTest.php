<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\Batch\FanoutDecision;
use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Event\BatchDispatchedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the *fallback* paths of the parallel-batch decision tree —
 * the only ones we can exercise without standing up a real worker pool.
 *
 * The "happy path" (actual loopback to a second PHP-FPM worker) is by
 * design impossible to test in a single-process PHPUnit run, since
 * MicroKernel doesn't fork child workers. The ParallelBatchExecutor's
 * own unit tests cover the HTTP fan-out mechanics; this file covers the
 * controller-level decision tree.
 */
final class ParallelBatchTest extends KernelTestCase
{
    public function testParallelBatchDisabledByDefaultEmitsSequentialDecision(): void
    {
        $kernel = $this->boot();
        $sink = new BatchEventSink();
        $this->attachDecisionListener($kernel, $sink);

        $this->callMethod($kernel, '[{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},{"jsonrpc":"2.0","method":"math.add","params":{"a":3,"b":4},"id":2}]');

        $this->assertCount(1, $sink->events);
        $this->assertSame(FanoutDecision::SequentialDisabled, $sink->events[0]->decision);
        $this->assertSame(2, $sink->events[0]->batchSize);
    }

    public function testBatchBelowMinSizeStaysSequentialEvenWhenEnabled(): void
    {
        $kernel = $this->boot([
            'parallel_batch' => ['enabled' => true, 'min_batch_size' => 3, 'budget_store' => 'null'],
        ]);
        $sink = new BatchEventSink();
        $this->attachDecisionListener($kernel, $sink);

        $this->callMethod($kernel, '[{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},{"jsonrpc":"2.0","method":"math.add","params":{"a":3,"b":4},"id":2}]');

        $this->assertSame(FanoutDecision::SequentialTooSmall, $sink->events[0]->decision);
    }

    public function testSubcallRequestDoesNotFanOutAgain(): void
    {
        $kernel = $this->boot(['parallel_batch' => ['enabled' => true, 'max_depth' => 1, 'budget_store' => 'null']]);
        $sink = new BatchEventSink();
        $this->attachDecisionListener($kernel, $sink);

        // Simulate an incoming sub-call (parent already at depth 1) by sending
        // the depth header. The new request is AT depth 1 already (>= max_depth),
        // so the controller must not attempt another fan-out.
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '[{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1},{"jsonrpc":"2.0","method":"math.add","params":{"a":3,"b":4},"id":2}]');
        $request->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '1');
        $kernel->handle($request);

        $this->assertSame(FanoutDecision::SequentialDepthLimit, $sink->events[0]->decision);
        $this->assertSame(1, $sink->events[0]->fanoutDepth);
    }

    public function testSingleCallNeverFansOut(): void
    {
        $kernel = $this->boot(['parallel_batch' => ['enabled' => true, 'min_batch_size' => 2, 'budget_store' => 'null']]);
        $sink = new BatchEventSink();
        $this->attachDecisionListener($kernel, $sink);

        $this->callMethod($kernel, '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}');

        // Non-batch single calls always go sequential — fan-out is a batch-only optimization.
        $this->assertSame(FanoutDecision::SequentialDisabled, $sink->events[0]->decision);
        $this->assertSame(1, $sink->events[0]->batchSize);
    }

    private function attachDecisionListener(\Symfony\Component\HttpKernel\KernelInterface $kernel, BatchEventSink $sink): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        $dispatcher->addListener(BatchDispatchedEvent::class, $sink->capture(...));
    }

    /**
     * @return array<string, mixed>
     */
    private function callMethod(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $body): array
    {
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);
        $content = (string) $response->getContent();
        if ('' === $content) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

/** @internal */
final class BatchEventSink
{
    /** @var list<BatchDispatchedEvent> */
    public array $events = [];

    public function capture(BatchDispatchedEvent $event): void
    {
        $this->events[] = $event;
    }
}
