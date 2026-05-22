<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Profiler;

use JsonRpcServer\Event\MethodInvocationCompletedEvent;
use JsonRpcServer\Event\MethodInvocationFailedEvent;
use JsonRpcServer\Event\MethodInvocationStartedEvent;
use JsonRpcServer\Profiler\JsonRpcDataCollector;
use JsonRpcServer\Profiler\RpcProfilerSubscriber;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcParams;
use PHPUnit\Framework\TestCase;

final class RpcProfilerSubscriberTest extends TestCase
{
    public function testSubscriberRecordsInvocationLifecycle(): void
    {
        $collector = new JsonRpcDataCollector();
        $subscriber = new RpcProfilerSubscriber($collector);
        $meta = new MethodMetadata(
            name: 'demo.echo',
            serviceClass: 'App\\Demo\\Echo',
            roles: [],
            description: null,
            parameters: [],
            returnType: 'array',
            isStreaming: false,
            streamFormat: null,
        );

        $params = new RpcParams(['x' => 1]);
        $subscriber->onStarted(new MethodInvocationStartedEvent($meta, $params));
        $subscriber->onCompleted(new MethodInvocationCompletedEvent($meta, $params, ['x' => 1], 0.02, cacheHit: false));
        $collector->collect(new \Symfony\Component\HttpFoundation\Request(), new \Symfony\Component\HttpFoundation\Response());

        $this->assertSame(1, $collector->getCallCount());
        $this->assertSame(20.0, $collector->getTotalDurationMs());
    }

    public function testSubscriberRecordsFailure(): void
    {
        $collector = new JsonRpcDataCollector();
        $subscriber = new RpcProfilerSubscriber($collector);
        $meta = new MethodMetadata(
            name: 'demo.fail',
            serviceClass: 'App\\Demo\\Fail',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
        );

        $params = new RpcParams(null);
        $subscriber->onStarted(new MethodInvocationStartedEvent($meta, $params));
        $subscriber->onFailed(new MethodInvocationFailedEvent($meta, $params, new \InvalidArgumentException('nope'), 0.01));

        $this->assertTrue($collector->hasError());
    }
}
