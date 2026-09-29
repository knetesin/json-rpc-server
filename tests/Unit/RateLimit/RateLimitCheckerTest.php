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
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

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

    public function testUserNamedAnonDoesNotShareBucketWithGuests(): void
    {
        $cache = new ArrayAdapter();
        $stack = $this->stackFrom('203.0.113.7');
        $storage = new TokenStorage();
        $checker = new RateLimitChecker($cache, $stack, new SecurityUserResolver($storage));
        $rateLimit = new RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::User);

        $checker->check($this->method(), $rateLimit);  // guest

        $storage->setToken(new TestBrowserToken(['ROLE_USER'], new InMemoryUser('anon', null, ['ROLE_USER'])));
        $checker->check($this->method(), $rateLimit);  // user "anon" has its own bucket

        $this->expectException(RateLimitExceededException::class);
        $checker->check($this->method(), $rateLimit);
    }

    public function testGuestsAreLimitedPerClientIp(): void
    {
        $cache = new ArrayAdapter();
        $rateLimit = new RateLimit(limit: 1, intervalSec: 60, scope: RateLimitScope::User);

        (new RateLimitChecker($cache, $this->stackFrom('203.0.113.7'), new SecurityUserResolver(null)))->check($this->method(), $rateLimit);
        (new RateLimitChecker($cache, $this->stackFrom('198.51.100.9'), new SecurityUserResolver(null)))->check($this->method(), $rateLimit);

        $this->expectException(RateLimitExceededException::class);
        (new RateLimitChecker($cache, $this->stackFrom('203.0.113.7'), new SecurityUserResolver(null)))->check($this->method(), $rateLimit);
    }

    private function stackFrom(string $ip): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/rpc', 'POST', server: ['REMOTE_ADDR' => $ip]));

        return $stack;
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
