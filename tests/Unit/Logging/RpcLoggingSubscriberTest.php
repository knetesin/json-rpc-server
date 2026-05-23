<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Logging;

use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException;
use Knetesin\JsonRpcServerBundle\Logging\RpcLoggingSubscriber;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class RpcLoggingSubscriberTest extends TestCase
{
    public function testStartedEmitsAtConfiguredLevel(): void
    {
        $logger = new InMemoryLogger();
        $sub = new RpcLoggingSubscriber($logger, levelStarted: LogLevel::DEBUG);

        $sub->onStarted(new MethodInvocationStartedEvent($this->meta('user.update'), new RpcParams(['id' => 7])));

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
        $this->assertSame('rpc.call.started', $logger->records[0]['message']);
        $this->assertSame('user.update', $logger->records[0]['context']['method']);
        $this->assertSame(['id' => 7], $logger->records[0]['context']['params']);
    }

    public function testCompletedRespectsSlowThreshold(): void
    {
        $logger = new InMemoryLogger();
        $sub = new RpcLoggingSubscriber(
            logger: $logger,
            levelCompleted: LogLevel::INFO,
            levelFailed: LogLevel::WARNING,
            slowThresholdMs: 100,
        );

        // 50 ms — under threshold, normal info level.
        $sub->onCompleted(new MethodInvocationCompletedEvent($this->meta('a'), new RpcParams(null), null, 0.05));
        // 200 ms — escalated to warning.
        $sub->onCompleted(new MethodInvocationCompletedEvent($this->meta('b'), new RpcParams(null), null, 0.2));

        $this->assertSame(LogLevel::INFO, $logger->records[0]['level']);
        $this->assertSame(LogLevel::WARNING, $logger->records[1]['level']);
        $this->assertSame(200, $logger->records[1]['context']['duration_ms']);
    }

    public function testFailedIncludesRpcCodeAndException(): void
    {
        $logger = new InMemoryLogger();
        $sub = new RpcLoggingSubscriber($logger);

        $sub->onFailed(new MethodInvocationFailedEvent(
            method: $this->meta('user.create'),
            params: new RpcParams(['email' => 'bad']),
            exception: new InvalidParamsException('Invalid params'),
            durationSec: 0.012,
        ));

        $record = $logger->records[0];
        $this->assertSame(LogLevel::WARNING, $record['level']);
        $this->assertSame(InvalidParamsException::class, $record['context']['exception_class']);
        $this->assertSame(-32602, $record['context']['rpc_code']);
        $this->assertInstanceOf(\Throwable::class, $record['context']['exception']);
    }

    public function testParamsAndResultLoggingCanBeDisabled(): void
    {
        $logger = new InMemoryLogger();
        $sub = new RpcLoggingSubscriber($logger, logParams: false, logResult: false);

        $sub->onCompleted(new MethodInvocationCompletedEvent(
            method: $this->meta('user.get'),
            params: new RpcParams(['secret' => 'shhh']),
            result: ['id' => 1, 'token' => 'leak'],
            durationSec: 0.001,
        ));

        $this->assertArrayNotHasKey('params', $logger->records[0]['context']);
        $this->assertArrayNotHasKey('result', $logger->records[0]['context']);
    }

    private function meta(string $name): MethodMetadata
    {
        return new MethodMetadata(
            name: $name,
            serviceClass: 'App\\Stub',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
        );
    }
}

/** @internal */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
