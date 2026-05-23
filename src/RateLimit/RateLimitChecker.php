<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\RateLimit;

use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitPolicy;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;
use Knetesin\JsonRpcServerBundle\Exception\RateLimitExceededException;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class RateLimitChecker
{
    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
        private readonly SecurityUserResolver $users,
    ) {
    }

    public function check(MethodMetadata $method, ?RateLimit $rateLimit): void
    {
        if (null === $rateLimit || RateLimitPolicy::NoLimit === $rateLimit->policy) {
            return;
        }

        $key = $this->buildKey($method->name, $rateLimit->scope);
        $limiter = $this->factoryFor($method->name, $rateLimit)->create($key);
        $reservation = $limiter->consume(1);

        if (!$reservation->isAccepted()) {
            $retryAfter = $reservation->getRetryAfter()->getTimestamp() - time();
            throw new RateLimitExceededException(\sprintf('Rate limit exceeded for %s', $method->name), retryAfter: max(0, $retryAfter));
        }
    }

    private function factoryFor(string $methodName, RateLimit $rateLimit): RateLimiterFactory
    {
        return $this->factories[$methodName] ??= new RateLimiterFactory(
            $this->buildConfig($methodName, $rateLimit),
            new CacheStorage($this->cache),
        );
    }

    /**
     * @return array{id: string, policy: string, limit?: int, interval?: string, rate?: array{amount: int, interval: string}}
     */
    private function buildConfig(string $methodName, RateLimit $rateLimit): array
    {
        $base = [
            'id' => 'rpc.'.$methodName,
            'policy' => $rateLimit->policy->value,
        ];

        // TokenBucket refills `limit` tokens over `intervalSec` — express that
        // to Symfony as `amount=limit, interval='Ns'`. Symfony then refills the
        // bucket linearly at limit/intervalSec per second, with a max burst
        // capped by `limit`.
        return match ($rateLimit->policy) {
            RateLimitPolicy::TokenBucket => $base + [
                'limit' => $rateLimit->limit,
                'rate' => [
                    'amount' => $rateLimit->limit,
                    'interval' => $rateLimit->intervalSec.' seconds',
                ],
            ],
            RateLimitPolicy::FixedWindow, RateLimitPolicy::SlidingWindow => $base + [
                'limit' => $rateLimit->limit,
                'interval' => $rateLimit->intervalSec.' seconds',
            ],
            // NoLimit is short-circuited in check() before we ever build a
            // factory; this branch exists only to keep the match exhaustive.
            RateLimitPolicy::NoLimit => $base,
        };
    }

    private function buildKey(string $methodName, RateLimitScope $scope): string
    {
        return $methodName.'|'.match ($scope) {
            RateLimitScope::GlobalScope => 'global',
            RateLimitScope::User => 'user:'.$this->users->getUserIdentifier(),
            RateLimitScope::Ip => 'ip:'.($this->requestStack->getMainRequest()?->getClientIp() ?? 'unknown'),
        };
    }
}
