<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Symfony\Component\HttpFoundation\Request;

final class EventsTest extends KernelTestCase
{
    public function testStartedAndCompletedFiredOnSuccess(): void
    {
        $kernel = $this->boot();
        $captured = $this->attachCaptureSubscriber($kernel);

        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"math.add","params":{"a":1,"b":2},"id":1}',
        );
        $kernel->handle($request);

        $this->assertCount(2, $captured);
        $this->assertInstanceOf(MethodInvocationStartedEvent::class, $captured[0]);
        $this->assertSame('math.add', $captured[0]->method->name);
        $this->assertInstanceOf(MethodInvocationCompletedEvent::class, $captured[1]);
        $this->assertSame(['sum' => 3], $captured[1]->result);
        $this->assertGreaterThanOrEqual(0.0, $captured[1]->durationSec);
    }

    public function testStartedAndFailedFiredOnError(): void
    {
        $kernel = $this->boot();
        $captured = $this->attachCaptureSubscriber($kernel);

        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"test.boom","params":{"kind":"access"},"id":1}',
        );
        $kernel->handle($request);

        $this->assertCount(2, $captured);
        $this->assertInstanceOf(MethodInvocationStartedEvent::class, $captured[0]);
        $this->assertInstanceOf(MethodInvocationFailedEvent::class, $captured[1]);
        $this->assertSame('test.boom', $captured[1]->method->name);
    }

    /**
     * @return \ArrayObject<int, object>
     */
    private function attachCaptureSubscriber(\Symfony\Component\HttpKernel\KernelInterface $kernel): \ArrayObject
    {
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $bus */
        $bus = $kernel->getContainer()->get('event_dispatcher');

        /** @var \ArrayObject<int, object> $events */
        $events = new \ArrayObject();
        $listener = static function (object $e) use ($events): void { $events->append($e); };

        $bus->addListener(MethodInvocationStartedEvent::class, $listener);
        $bus->addListener(MethodInvocationCompletedEvent::class, $listener);
        $bus->addListener(MethodInvocationFailedEvent::class, $listener);

        return $events;
    }
}
