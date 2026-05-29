<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\RateLimit;

use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\Attribute\RateLimitScope;
use Knetesin\JsonRpcServerBundle\Exception\RateLimitExceededException;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitBypassInterface;
use Knetesin\JsonRpcServerBundle\RateLimit\RateLimitChecker;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

final class RateLimitCheckerTest extends TestCase
{
    public function testEnforcesLimitWithoutBypass(): void
    {
        $checker = $this->checker();
        $method = $this->method();
        $rateLimit = new RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::GlobalScope);

        $checker->check($method, $rateLimit);

        $this->expectException(RateLimitExceededException::class);
        $checker->check($method, $rateLimit);
    }

    public function testBypassSkipsConsumption(): void
    {
        $checker = $this->checker(new AlwaysBypass());
        $method = $this->method();
        $rateLimit = new RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::GlobalScope);

        // Without the bypass the second call would throw; here it never does.
        $checker->check($method, $rateLimit);
        $checker->check($method, $rateLimit);
        $checker->check($method, $rateLimit);

        $this->addToAssertionCount(1);
    }

    public function testNonMatchingBypassDefersToEnforcement(): void
    {
        $checker = $this->checker(new NeverBypass());
        $method = $this->method();
        $rateLimit = new RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::GlobalScope);

        $checker->check($method, $rateLimit);

        $this->expectException(RateLimitExceededException::class);
        $checker->check($method, $rateLimit);
    }

    public function testFirstAcceptingBypassWinsInChain(): void
    {
        $checker = $this->checker(new NeverBypass(), new AlwaysBypass());
        $method = $this->method();
        $rateLimit = new RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::GlobalScope);

        $checker->check($method, $rateLimit);
        $checker->check($method, $rateLimit);

        $this->addToAssertionCount(1);
    }

    private function checker(RateLimitBypassInterface ...$bypasses): RateLimitChecker
    {
        return new RateLimitChecker(
            new ArrayAdapter(),
            new RequestStack(),
            new SecurityUserResolver(null),
            $bypasses,
        );
    }

    private function method(): MethodMetadata
    {
        return new MethodMetadata(
            name: 'test.method',
            serviceClass: 'object',
            roles: [],
            description: null,
            parameters: [],
            returnType: null,
            isStreaming: false,
            streamFormat: null,
        );
    }
}

final class AlwaysBypass implements RateLimitBypassInterface
{
    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool
    {
        return true;
    }
}

final class NeverBypass implements RateLimitBypassInterface
{
    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool
    {
        return false;
    }
}
