<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Profiler;

use JsonRpcServer\Profiler\JsonRpcDataCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class JsonRpcDataCollectorTest extends TestCase
{
    public function testCollectsSuccessfulCall(): void
    {
        $collector = new JsonRpcDataCollector();
        $collector->startCall('math.add', 'App\\Math\\Add', ['a' => 1, 'b' => 2]);
        $collector->completeCall(0.012, ['sum' => 3], false);
        $collector->collect(new Request(), new Response());

        $this->assertSame(1, $collector->getCallCount());
        $this->assertSame(12.0, $collector->getTotalDurationMs());
        $this->assertFalse($collector->hasError());
    }

    public function testCollectsFailedCall(): void
    {
        $collector = new JsonRpcDataCollector();
        $collector->startCall('test.boom', 'App\\Test\\Boom', []);
        $collector->failCall(0.005, new \RuntimeException('boom'));
        $collector->collect(new Request(), new Response());

        $this->assertTrue($collector->hasError());
        $this->assertSame(5.0, $collector->getTotalDurationMs());
    }

    public function testResetClearsCalls(): void
    {
        $collector = new JsonRpcDataCollector();
        $collector->startCall('ping', 'App\\Ping', []);
        $collector->completeCall(0.001, 'pong', true);
        $collector->reset();

        $collector->collect(new Request(), new Response());
        $this->assertSame(0, $collector->getCallCount());
    }
}
