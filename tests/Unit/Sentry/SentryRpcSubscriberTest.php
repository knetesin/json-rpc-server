<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Sentry;

use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Knetesin\JsonRpcServerBundle\Exception\InvalidParamsException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use Knetesin\JsonRpcServerBundle\Sentry\SentryRpcSubscriber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class SentryRpcSubscriberTest extends TestCase
{
    public function testStartedEmitsBreadcrumbAndTag(): void
    {
        $hub = new SpyHub();
        $sub = new SentryRpcSubscriber($hub, breadcrumbs: true, tagMethod: true);

        $sub->onStarted(new MethodInvocationStartedEvent(
            $this->meta('user.update'),
            new RpcParams(['id' => 9]),
        ));

        $this->assertCount(1, $hub->breadcrumbs);
        $this->assertSame('rpc', $hub->breadcrumbs[0]->getCategory());
        $this->assertStringContainsString('user.update', (string) $hub->breadcrumbs[0]->getMessage());
        $this->assertSame('user.update', $hub->tags['rpc.method']);
    }

    public function testFailedRespectsIgnoreList(): void
    {
        $hub = new SpyHub();
        $sub = new SentryRpcSubscriber(
            hub: $hub,
            breadcrumbs: true,
            ignoreExceptions: [InvalidParamsException::class],
        );

        $sub->onFailed(new MethodInvocationFailedEvent(
            $this->meta('user.create'),
            new RpcParams(null),
            new InvalidParamsException('Invalid params'),
            0.01,
        ));

        // Ignored exception — no error breadcrumb emitted.
        $this->assertSame([], $hub->breadcrumbs);
    }

    public function testFailedNonIgnoredEmitsErrorBreadcrumb(): void
    {
        $hub = new SpyHub();
        $sub = new SentryRpcSubscriber($hub, breadcrumbs: true);

        $sub->onFailed(new MethodInvocationFailedEvent(
            $this->meta('user.update'),
            new RpcParams(null),
            new \RuntimeException('boom'),
            0.05,
        ));

        $this->assertCount(1, $hub->breadcrumbs);
        $this->assertSame(Breadcrumb::LEVEL_ERROR, $hub->breadcrumbs[0]->getLevel());
    }

    /**
     * Regression for the previous `array<string, Span>` implementation: when
     * the same method appeared twice in a batch, the second `onStarted` would
     * overwrite the first span and the first one would never get finished.
     * The stack-based version pops in LIFO order, so both spans finish cleanly.
     */
    public function testRepeatedMethodInBatchFinishesBothSpans(): void
    {
        $hub = new SpyHub();
        $hub->transaction = new Transaction(new TransactionContext('test'));
        $sub = new SentryRpcSubscriber($hub, breadcrumbs: false, tagMethod: false, transactions: true);

        $event = new MethodInvocationStartedEvent($this->meta('user.update'), new RpcParams(['id' => 1]));
        $sub->onStarted($event);
        $sub->onStarted($event);

        $this->assertCount(2, $hub->setSpanCalls, 'each onStarted should set a span as current');
        $first = $hub->setSpanCalls[0];
        $second = $hub->setSpanCalls[1];
        $this->assertNotSame($first, $second, 'distinct spans for distinct invocations');

        $sub->onCompleted(new MethodInvocationCompletedEvent(
            $this->meta('user.update'),
            new RpcParams(['id' => 1]),
            null,
            0.01,
        ));
        $sub->onCompleted(new MethodInvocationCompletedEvent(
            $this->meta('user.update'),
            new RpcParams(['id' => 1]),
            null,
            0.01,
        ));

        $this->assertNotNull($first?->getEndTimestamp(), 'first span finished');
        $this->assertNotNull($second?->getEndTimestamp(), 'second span finished');
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

/** @internal Minimal HubInterface spy. Only the methods the subscriber touches are wired. */
final class SpyHub implements HubInterface
{
    /** @var list<Breadcrumb> */
    public array $breadcrumbs = [];

    /** @var array<string, string> */
    public array $tags = [];

    public ?Transaction $transaction = null;

    private ?Span $currentSpan = null;

    /** @var list<?Span> Every value passed to setSpan(), in call order. */
    public array $setSpanCalls = [];

    public function addBreadcrumb(Breadcrumb $breadcrumb): bool
    {
        $this->breadcrumbs[] = $breadcrumb;

        return true;
    }

    public function configureScope(callable $callback): void
    {
        $scope = new TagRecordingScope($this);
        $callback($scope);
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setSpan(?Span $span): HubInterface
    {
        $this->setSpanCalls[] = $span;
        $this->currentSpan = $span;

        return $this;
    }

    // --- everything else stubbed; subscriber doesn't call these ---
    public function getClient(): ?\Sentry\ClientInterface
    {
        return null;
    }

    public function getLastEventId(): ?\Sentry\EventId
    {
        return null;
    }

    public function pushScope(): Scope
    {
        return new Scope();
    }

    public function popScope(): bool
    {
        return true;
    }

    public function withScope(callable $callback): mixed
    {
        return $callback(new Scope());
    }

    public function bindClient(\Sentry\ClientInterface $client): void
    {
    }

    public function captureMessage(string $message, ?\Sentry\Severity $level = null, ?\Sentry\EventHint $hint = null): ?\Sentry\EventId
    {
        return null;
    }

    public function captureException(\Throwable $exception, ?\Sentry\EventHint $hint = null): ?\Sentry\EventId
    {
        return null;
    }

    public function captureEvent(\Sentry\Event $event, ?\Sentry\EventHint $hint = null): ?\Sentry\EventId
    {
        return null;
    }

    public function captureLastError(?\Sentry\EventHint $hint = null): ?\Sentry\EventId
    {
        return null;
    }

    public function getIntegration(string $className): ?\Sentry\Integration\IntegrationInterface
    {
        return null;
    }

    public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
    {
        throw new \LogicException('not used by subscriber');
    }

    public function setTransaction(?Transaction $transaction): HubInterface
    {
        return $this;
    }

    public function getSpan(): ?Span
    {
        return $this->currentSpan;
    }

    public function captureCheckIn(string $slug, \Sentry\CheckInStatus $status, $duration = null, ?\Sentry\MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
    {
        return null;
    }
}

/**
 * @internal Captures setTag() calls and pushes them into SpyHub::$tags.
 *           Sentry's `Scope::getTags()` is not part of the public API, so we
 *           intercept writes instead of reading them back.
 */
final class TagRecordingScope extends Scope
{
    public function __construct(private readonly SpyHub $hub)
    {
        parent::__construct();
    }

    public function setTag(string $key, string $value): Scope
    {
        $this->hub->tags[$key] = $value;

        return $this;
    }
}
