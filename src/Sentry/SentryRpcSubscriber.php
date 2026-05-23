<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Sentry;

use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Optional Sentry bridge for RPC invocations.
 *
 * Registered by RpcExtension only when `json_rpc_server.sentry.enabled: true` AND
 * sentry/sentry-symfony is installed (the class_exists guard happens at
 * compile time so production with no Sentry never sees this class).
 *
 * What it does:
 *   - breadcrumbs: emits an `rpc` category breadcrumb on each started /
 *     completed / failed event;
 *   - tag: sets `rpc.method = <name>` so issues can be filtered / grouped;
 *   - transactions: optionally opens a child span on the active Sentry
 *     transaction for each call, closed on completion or failure.
 *
 * Exceptions listed in `json_rpc_server.sentry.ignore_exceptions` (defaults to standard
 * client-side RPC errors) skip breadcrumbs / spans so Sentry isn't polluted
 * with InvalidParams / AccessDenied noise. The underlying Sentry SDK still
 * sees the exception through the PSR-3 logger path if you choose to keep it.
 */
final class SentryRpcSubscriber implements EventSubscriberInterface
{
    /**
     * Active span frames. LIFO matches sequential JSON-RPC dispatch: nested or
     * repeated method calls in a batch each push a frame; completed / failed
     * pops the most recent matching one. Keying by method name (as we used to
     * do) overwrites the first span when the same method appears twice in a
     * batch — the overwritten span never gets finished.
     *
     * @var \SplStack<array{span: Span, method: string, previous: ?Span}>
     */
    private \SplStack $activeFrames;

    /**
     * @param list<class-string<\Throwable>> $ignoreExceptions
     */
    public function __construct(
        private readonly HubInterface $hub,
        private readonly bool $breadcrumbs = true,
        private readonly bool $tagMethod = true,
        private readonly bool $transactions = false,
        private readonly array $ignoreExceptions = [],
    ) {
        $this->activeFrames = new \SplStack();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MethodInvocationStartedEvent::class => 'onStarted',
            MethodInvocationCompletedEvent::class => 'onCompleted',
            MethodInvocationFailedEvent::class => 'onFailed',
        ];
    }

    public function onStarted(MethodInvocationStartedEvent $event): void
    {
        $method = $event->method->name;

        if ($this->tagMethod) {
            $this->hub->configureScope(static function (\Sentry\State\Scope $scope) use ($method): void {
                $scope->setTag('rpc.method', $method);
            });
        }

        if ($this->breadcrumbs) {
            $this->hub->addBreadcrumb(new Breadcrumb(
                level: Breadcrumb::LEVEL_INFO,
                type: Breadcrumb::TYPE_DEFAULT,
                category: 'rpc',
                message: 'started: '.$method,
                metadata: ['params' => $event->params->all()],
            ));
        }

        if ($this->transactions) {
            $transaction = $this->hub->getTransaction();
            if (null === $transaction) {
                return;
            }
            $context = (new SpanContext())
                ->setOp('rpc.call')
                ->setDescription($method);
            $previous = $this->hub->getSpan();
            $span = $transaction->startChild($context);
            $this->activeFrames->push([
                'span' => $span,
                'method' => $method,
                'previous' => $previous,
            ]);
            $this->hub->setSpan($span);
        }
    }

    public function onCompleted(MethodInvocationCompletedEvent $event): void
    {
        $method = $event->method->name;

        if ($this->breadcrumbs) {
            $this->hub->addBreadcrumb(new Breadcrumb(
                level: Breadcrumb::LEVEL_INFO,
                type: Breadcrumb::TYPE_DEFAULT,
                category: 'rpc',
                message: \sprintf('ok: %s (%.1f ms)', $method, $event->durationSec * 1000),
                metadata: ['cache_hit' => $event->cacheHit],
            ));
        }

        $this->finishSpan($method, 'ok');
    }

    public function onFailed(MethodInvocationFailedEvent $event): void
    {
        $method = $event->method->name;
        $ignored = $this->isIgnored($event->exception);

        if ($this->breadcrumbs && !$ignored) {
            $this->hub->addBreadcrumb(new Breadcrumb(
                level: Breadcrumb::LEVEL_ERROR,
                type: Breadcrumb::TYPE_ERROR,
                category: 'rpc',
                message: \sprintf('failed: %s — %s', $method, $event->exception::class),
                metadata: [
                    'duration_ms' => (int) ($event->durationSec * 1000),
                    'message' => $event->exception->getMessage(),
                ],
            ));
        }

        $this->finishSpan($method, $ignored ? 'ok' : 'internal_error');
    }

    private function isIgnored(\Throwable $e): bool
    {
        foreach ($this->ignoreExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function finishSpan(string $method, string $status): void
    {
        $frame = $this->popFrame($method);
        if (null === $frame) {
            return;
        }

        $frame['span']->setStatus(\Sentry\Tracing\SpanStatus::createFromHTTPStatusCode(
            'ok' === $status ? 200 : 500,
        ));
        $frame['span']->finish();
        // Restore the previously-active span (parent transaction or outer
        // RPC frame). Without this, the finished span stays "current" and
        // any later instrumentation attaches children to a dead span.
        $this->hub->setSpan($frame['previous']);
    }

    /**
     * @return array{span: Span, method: string, previous: ?Span}|null
     */
    private function popFrame(string $method): ?array
    {
        if ($this->activeFrames->isEmpty()) {
            return null;
        }
        $top = $this->activeFrames->top();
        // Defensive: a misbehaving subscriber could re-order events. Dispatch
        // is sequential in practice, so the top frame should always match.
        if ($top['method'] !== $method) {
            return null;
        }

        return $this->activeFrames->pop();
    }
}
