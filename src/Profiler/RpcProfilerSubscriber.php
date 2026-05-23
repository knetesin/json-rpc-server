<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Profiler;

use Knetesin\JsonRpcServerBundle\Event\BatchDispatchedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class RpcProfilerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly JsonRpcDataCollector $collector,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MethodInvocationStartedEvent::class => 'onStarted',
            MethodInvocationCompletedEvent::class => 'onCompleted',
            MethodInvocationFailedEvent::class => 'onFailed',
            BatchDispatchedEvent::class => 'onBatchDispatched',
        ];
    }

    public function onStarted(MethodInvocationStartedEvent $event): void
    {
        $this->collector->startCall(
            $event->method->name,
            $event->method->serviceClass,
            $event->params->all(),
        );
    }

    public function onCompleted(MethodInvocationCompletedEvent $event): void
    {
        $this->collector->completeCall(
            $event->durationSec,
            $event->result,
            $event->cacheHit,
        );
    }

    public function onFailed(MethodInvocationFailedEvent $event): void
    {
        $this->collector->failCall($event->durationSec, $event->exception);
    }

    public function onBatchDispatched(BatchDispatchedEvent $event): void
    {
        $this->collector->recordDispatch(
            $event->batchSize,
            $event->decision->value,
            $event->totalDurationSec,
            $event->subcallDurationsSec,
            $event->fanoutDepth,
            $event->inflightAtStart,
        );
    }
}
