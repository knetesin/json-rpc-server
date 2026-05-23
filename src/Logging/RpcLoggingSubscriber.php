<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Logging;

use Knetesin\JsonRpcServerBundle\Event\MethodInvocationCompletedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationStartedEvent;
use Knetesin\JsonRpcServerBundle\Exception\RpcException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Built-in PSR-3 logger for RPC method invocations.
 *
 * Registered by RpcExtension only when `json_rpc_server.logging.enabled: true`. All
 * settings are wired from `json_rpc_server.logging.*` — see Configuration.php.
 *
 * Three log lines per invocation (with channel = the configured logger):
 *
 *   - `rpc.call.started`   (default level: debug)
 *   - `rpc.call.completed` (default level: info)
 *   - `rpc.call.failed`    (default level: warning)
 *
 * A `slow_threshold_ms` escalates slow completions to the failure level so
 * alerting that already filters on `>=warning` picks them up without code
 * changes.
 */
final readonly class RpcLoggingSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $allowedLevels whitelist of PSR-3 levels (defensive; the config enum already restricts these)
     */
    private const array ALLOWED_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    public function __construct(
        private LoggerInterface $logger,
        private string $levelStarted = LogLevel::DEBUG,
        private string $levelCompleted = LogLevel::INFO,
        private string $levelFailed = LogLevel::WARNING,
        private bool $logParams = true,
        private bool $logResult = false,
        private ?int $slowThresholdMs = null,
    ) {
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
        $this->log($this->levelStarted, 'rpc.call.started', [
            'method' => $event->method->name,
            'params' => $this->logParams ? $event->params->all() : null,
        ]);
    }

    public function onCompleted(MethodInvocationCompletedEvent $event): void
    {
        $durationMs = (int) ($event->durationSec * 1000);
        $level = null !== $this->slowThresholdMs && $durationMs >= $this->slowThresholdMs
            ? $this->levelFailed
            : $this->levelCompleted;

        $context = [
            'method' => $event->method->name,
            'duration_ms' => $durationMs,
            'cache_hit' => $event->cacheHit,
        ];
        if ($this->logParams) {
            $context['params'] = $event->params->all();
        }
        if ($this->logResult) {
            $context['result'] = $event->result;
        }

        $this->log($level, 'rpc.call.completed', $context);
    }

    public function onFailed(MethodInvocationFailedEvent $event): void
    {
        $context = [
            'method' => $event->method->name,
            'duration_ms' => (int) ($event->durationSec * 1000),
            'exception_class' => $event->exception::class,
            'exception_message' => $event->exception->getMessage(),
            // The exception object itself goes under the conventional `exception`
            // key so Monolog's IntrospectionProcessor and Sentry pick it up.
            'exception' => $event->exception,
        ];
        if ($event->exception instanceof RpcException) {
            $context['rpc_code'] = $event->exception->rpcCode();
        }
        if ($this->logParams) {
            $context['params'] = $event->params->all();
        }

        $this->log($this->levelFailed, 'rpc.call.failed', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (!\in_array($level, self::ALLOWED_LEVELS, true)) {
            // Mis-configured level: fall back to info rather than crashing the dispatch.
            $level = LogLevel::INFO;
        }
        $this->logger->log($level, $message, $context);
    }
}
