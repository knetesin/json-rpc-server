<?php

declare(strict_types=1);

namespace JsonRpcServer\Dispatcher;

use JsonRpcServer\Attribute\RoleMatch;
use JsonRpcServer\Cache\CacheChecker;
use JsonRpcServer\Event\MethodInvocationCompletedEvent;
use JsonRpcServer\Event\MethodInvocationFailedEvent;
use JsonRpcServer\Event\MethodInvocationStartedEvent;
use JsonRpcServer\Exception\AccessDeniedException;
use JsonRpcServer\RateLimit\RateLimitChecker;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Registry\MethodRegistry;
use JsonRpcServer\Request\RpcRequest;
use JsonRpcServer\Resolver\ArgumentResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class Dispatcher
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MethodRegistry $registry,
        private readonly ArgumentResolver $resolver,
        private readonly NormalizerInterface $normalizer,
        private readonly ?AuthorizationCheckerInterface $authChecker = null,
        private readonly ?RateLimitChecker $rateLimitChecker = null,
        private readonly ?CacheChecker $cacheChecker = null,
        private readonly ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
        /** Whether AccessDenied messages name the missing role(s). */
        private readonly bool $exposeRoleNames = true,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function call(RpcRequest $request, bool $applyRateLimit = true): mixed
    {
        $meta = $this->registry->get($request->method);
        if ($meta->isDeprecated()) {
            $this->logger->warning('Deprecated RPC method called', [
                'method' => $meta->name,
                'reason' => $meta->deprecated,
            ]);
        }
        $this->checkRoles($meta);
        if ($applyRateLimit) {
            $this->rateLimitChecker?->check($meta, $meta->rateLimit);
        }

        // Cache lookup — skip for notifications (they typically carry side
        // effects the client wants applied each time) and for unset cache.
        $cacheable = null !== $meta->cache && null !== $this->cacheChecker && !$request->isNotification;
        if ($cacheable) {
            $hit = $this->cacheChecker->get($meta, $request);
            if (null !== $hit) {
                $this->events?->dispatch(new MethodInvocationStartedEvent($meta, $request->params));
                $this->events?->dispatch(new MethodInvocationCompletedEvent($meta, $request->params, $hit->value, 0.0, cacheHit: true));

                return $hit->value;
            }
        }

        $handler = $this->registry->handler($request->method);
        if (!\is_callable($handler)) {
            throw new \LogicException(\sprintf('RPC method "%s" handler is not invokable.', $request->method));
        }
        $args = $this->resolver->resolve($meta, $request);

        $this->events?->dispatch(new MethodInvocationStartedEvent($meta, $request->params));
        $startedAt = microtime(true);

        try {
            $result = $handler(...$args);
            // Normalize before caching so the pool stores plain arrays/scalars
            // instead of opaque objects (Doctrine proxies, DTOs with custom
            // serialize semantics, etc.). Hits and misses then return the same
            // shape, and event listeners see a consistent `result`.
            //
            // Streaming methods return Generators that must remain unconsumed —
            // StreamController normalizes each row individually as the iterator
            // advances.
            if (!$meta->isStreaming) {
                $result = $this->normalize($result);
            }
            if ($cacheable) {
                $this->cacheChecker->set($meta, $request, $result);
            }
            $this->events?->dispatch(new MethodInvocationCompletedEvent(
                $meta,
                $request->params,
                $result,
                microtime(true) - $startedAt,
            ));

            return $result;
        } catch (\Throwable $e) {
            $this->events?->dispatch(new MethodInvocationFailedEvent(
                $meta,
                $request->params,
                $e,
                microtime(true) - $startedAt,
            ));
            throw $e;
        }
    }

    public function metadata(string $method): MethodMetadata
    {
        return $this->registry->get($method);
    }

    private function normalize(mixed $value): mixed
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        return $this->normalizer->normalize($value, 'json', ['skip_null_values' => false]);
    }

    public function handler(string $method): object
    {
        return $this->registry->handler($method);
    }

    private function checkRoles(MethodMetadata $meta): void
    {
        // No roles = public method. The dispatcher does not call isGranted,
        // so anonymous requests pass through (provided the project's firewall
        // also allows them). Authentication remains a firewall concern.
        if ([] === $meta->roles) {
            return;
        }

        if (null === $this->authChecker) {
            throw new AccessDeniedException('This method requires authentication/authorization. Install symfony/security-bundle or remove roles from the method.');
        }

        $granted = array_filter(
            $meta->roles,
            fn (string $role): bool => $this->authChecker->isGranted($role),
        );

        $ok = match ($meta->rolesMatch) {
            RoleMatch::Any => [] !== $granted,
            RoleMatch::All => \count($granted) === \count($meta->roles),
        };

        if (!$ok) {
            throw new AccessDeniedException($this->accessDeniedMessage($meta));
        }
    }

    private function accessDeniedMessage(MethodMetadata $meta): string
    {
        // Redact in prod-style deployments where role identifiers carry
        // business meaning ("ROLE_BILLING_*"). Toggle via
        // `json_rpc_server.security.expose_role_names`.
        if (!$this->exposeRoleNames) {
            return 'Access denied';
        }

        $required = implode(', ', $meta->roles);

        return match ($meta->rolesMatch) {
            RoleMatch::Any => \sprintf('One of the following roles is required: %s', $required),
            RoleMatch::All => \sprintf('All of the following roles are required: %s', $required),
        };
    }
}
