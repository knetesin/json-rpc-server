<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\Cache\RpcCacheInvalidator;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\CacheEncoding\CachedLabel;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\CachePurge\CounterUser;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods\CounterGlobal;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods\CounterIp;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods\CounterParam;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Methods\CounterVary;
use Symfony\Component\HttpFoundation\Request;

final class CacheTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static counters survive between PHPUnit tests in the same process.
        // Reset them so each scenario starts from zero — also resets any
        // residual cache entries from earlier tests when boot() creates a
        // new container.
        foreach ([CounterGlobal::class, CounterIp::class, CounterParam::class, CounterVary::class, CounterUser::class, CachedLabel::class] as $cls) {
            $reflection = new \ReflectionClass($cls);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                $prop->setValue(null, $prop->getDefaultValue());
            }
        }
    }

    public function testGlobalScopeHitShortCircuitsHandler(): void
    {
        $kernel = $this->boot();
        $first = $this->call($kernel, 'cache.counter_global');
        $second = $this->call($kernel, 'cache.counter_global');

        // both share the same global slot — second response equals the first
        $this->assertSame(1, $first['result']['n']);
        $this->assertSame(1, $second['result']['n']);
    }

    public function testCacheKeyVariesByParams(): void
    {
        $kernel = $this->boot();
        $a1 = $this->call($kernel, 'cache.counter_param', ['k' => 'a']);
        $b1 = $this->call($kernel, 'cache.counter_param', ['k' => 'b']);
        $a2 = $this->call($kernel, 'cache.counter_param', ['k' => 'a']);

        $this->assertSame(['k' => 'a', 'n' => 1], $a1['result']);
        $this->assertSame(['k' => 'b', 'n' => 1], $b1['result']);
        // same params → cache hit, still 1
        $this->assertSame(['k' => 'a', 'n' => 1], $a2['result']);
    }

    public function testCustomScopeContributorPartitionsCache(): void
    {
        $kernel = $this->boot();
        $us1 = $this->callWithHeader($kernel, 'cache.counter_vary', 'X-Country', 'US');
        $de1 = $this->callWithHeader($kernel, 'cache.counter_vary', 'X-Country', 'DE');
        $us2 = $this->callWithHeader($kernel, 'cache.counter_vary', 'X-Country', 'US');

        $this->assertSame(1, $us1['result']['n']);
        // different country dimension → handler ran again → counter incremented
        $this->assertSame(2, $de1['result']['n']);
        // back to US → cached
        $this->assertSame(1, $us2['result']['n']);
    }

    public function testBuiltInIpScopePartitionsCache(): void
    {
        $kernel = $this->boot();
        $a1 = $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.1');
        $b1 = $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.2');
        $a2 = $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.1');

        $this->assertSame(1, $a1['result']['n']);
        $this->assertSame(2, $b1['result']['n']);
        $this->assertSame(1, $a2['result']['n'], 'returning client hits cached slot');
    }

    /**
     * @return array<string, mixed>
     */
    private function callFromIp(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $method, string $ip): array
    {
        $body = $this->jsonEncode(['jsonrpc' => '2.0', 'method' => $method, 'id' => 1]);
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], content: $body);
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        return $this->decodeJsonResponse($response);
    }

    public function testNotificationsBypassCacheButRunHandler(): void
    {
        // 1) seed cache with a real request (handler runs, n=1, cache stores n=1)
        // 2) notification — handler runs again (side effect, counter → 2),
        //    but cache GET/SET both skipped
        // 3) real request — cache HIT, returns stored n=1
        $kernel = $this->boot();
        $seed = $this->call($kernel, 'cache.counter_global');
        $this->assertSame(1, $seed['result']['n']);

        $req = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","method":"cache.counter_global"}'
        );
        $kernel->handle($req);

        $hit = $this->call($kernel, 'cache.counter_global');
        $this->assertSame(1, $hit['result']['n'], 'cached value must survive an interleaved notification');
    }

    public function testInvalidatorPurgesSingleKey(): void
    {
        $kernel = $this->boot();
        $first = $this->call($kernel, 'cache.counter_param', ['k' => 'x']);
        $cachedAgain = $this->call($kernel, 'cache.counter_param', ['k' => 'x']);

        $this->assertSame(1, $first['result']['n']);
        $this->assertSame(1, $cachedAgain['result']['n'], 'second call must hit cache');

        /** @var RpcCacheInvalidator $invalidator */
        $invalidator = $kernel->getContainer()->get(RpcCacheInvalidator::class);
        $invalidator->purge('cache.counter_param', ['k' => 'x']);

        $afterPurge = $this->call($kernel, 'cache.counter_param', ['k' => 'x']);
        $this->assertSame(2, $afterPurge['result']['n'], 'handler must rerun after purge');
    }

    public function testPurgeWithoutScopeKeyTargetsTheCallersOwnSlot(): void
    {
        $kernel = $this->boot();
        $seed = $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.1');

        // Outside a request the caller's own IP scope is "ip:unknown", not the client's slot.
        $this->invalidator($kernel)->purge('cache.counter_ip');
        $afterPurge = $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.1');

        $this->assertSame(1, $seed['result']['n']);
        $this->assertSame(1, $afterPurge['result']['n']);
    }

    public function testPurgeWithScopeKeyTargetsAnotherCallersIpEntry(): void
    {
        $kernel = $this->boot();
        $this->assertSame(1, $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.1')['result']['n']);
        $this->assertSame(2, $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.2')['result']['n']);

        $this->invalidator($kernel)->purge('cache.counter_ip', null, 'ip:10.0.0.1');

        $this->assertSame(3, $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.1')['result']['n']);
        $this->assertSame(2, $this->callFromIp($kernel, 'cache.counter_ip', '10.0.0.2')['result']['n'], 'other owners keep their entry');
    }

    public function testPurgeWithScopeKeyTargetsAnotherUsersEntry(): void
    {
        $kernel = $this->boot([], ['CachePurge']);
        $seed = $this->callAsUser($kernel, 'cache.counter_user');
        $hit = $this->callAsUser($kernel, 'cache.counter_user');

        // TestAuthenticationListener authenticates every X-Test-Roles caller as "tester".
        $this->invalidator($kernel)->purge('cache.counter_user', null, 'user:tester');
        $afterPurge = $this->callAsUser($kernel, 'cache.counter_user');

        $this->assertSame(1, $seed['result']['n']);
        $this->assertSame(1, $hit['result']['n']);
        $this->assertSame(2, $afterPurge['result']['n']);
    }

    public function testPurgeRejectsScopeKeyForMethodWithoutScope(): void
    {
        $kernel = $this->boot();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RPC method cache.counter_param has no cache scope');

        $this->invalidator($kernel)->purge('cache.counter_param', ['k' => 'x'], 'user:tester');
    }

    public function testUnencodableResultIsNotCached(): void
    {
        $kernel = $this->boot([], ['CacheEncoding']);
        CachedLabel::$broken = true;
        $this->assertSame(-32603, $this->call($kernel, 'cache.label')['error']['code']);

        CachedLabel::$broken = false;
        $this->assertSame('café', $this->call($kernel, 'cache.label')['result']);
    }

    public function testCacheUsesConfiguredEncodeFlags(): void
    {
        // With JSON_INVALID_UTF8_SUBSTITUTE the broken value is encodable, so it
        // gets cached and is served even after the source is fixed.
        $kernel = $this->boot(['json' => ['encode_flags' => \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE]], ['CacheEncoding']);
        CachedLabel::$broken = true;
        $miss = $this->call($kernel, 'cache.label');
        $this->assertSame("caf\u{FFFD}", $miss['result']);

        CachedLabel::$broken = false;
        $hit = $this->call($kernel, 'cache.label');
        $this->assertSame("caf\u{FFFD}", $hit['result']);
    }

    public function testInvalidatorPurgeMethodNeedsTagAwarePool(): void
    {
        $kernel = $this->boot();
        $this->call($kernel, 'cache.counter_param', ['k' => 'a']);
        $this->call($kernel, 'cache.counter_param', ['k' => 'b']);

        /** @var RpcCacheInvalidator $invalidator */
        $invalidator = $kernel->getContainer()->get(RpcCacheInvalidator::class);
        $result = $invalidator->purgeMethod('cache.counter_param');

        // cache.app in Symfony framework defaults to TagAwareAdapter, so this
        // should succeed. If it doesn't, the test reveals that the test kernel
        // is using a non-tag-aware pool and we'd need to configure one.
        $this->assertTrue($result, 'tag-aware pool must accept method-wide purge');

        $a = $this->call($kernel, 'cache.counter_param', ['k' => 'a']);
        $b = $this->call($kernel, 'cache.counter_param', ['k' => 'b']);
        // Each handler ran a 2nd time since the cache was wiped by tag.
        $this->assertSame(2, $a['result']['n']);
        $this->assertSame(2, $b['result']['n']);
    }

    public function testInvalidatorIgnoresUnknownMethod(): void
    {
        $kernel = $this->boot();
        /** @var RpcCacheInvalidator $invalidator */
        $invalidator = $kernel->getContainer()->get(RpcCacheInvalidator::class);
        $this->assertFalse($invalidator->purge('nope.does_not_exist', []));
        $this->assertFalse($invalidator->purgeMethod('nope.does_not_exist'));
    }

    public function testErrorsAreNotCached(): void
    {
        // test.echo validates message NotBlank — first call errors, second
        // with valid params must NOT see a cached error.
        $kernel = $this->boot();
        $bad = $this->callRaw($kernel, '{"jsonrpc":"2.0","method":"test.echo","params":{"message":""},"id":1}');
        $good = $this->callRaw($kernel, '{"jsonrpc":"2.0","method":"test.echo","params":{"message":"hi"},"id":2}');

        $this->assertSame(-32602, $bad['error']['code']);
        $this->assertSame('hi', $good['result']['pong']);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $method, array $params = []): array
    {
        $body = $this->jsonEncode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]);

        return $this->callRaw($kernel, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function callWithHeader(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $method, string $headerName, string $headerValue): array
    {
        $body = $this->jsonEncode(['jsonrpc' => '2.0', 'method' => $method, 'id' => 1]);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', $headerName)) => $headerValue,
        ];
        $request = Request::create('/rpc', 'POST', server: $server, content: $body);
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode(), $this->responseContent($response));

        return $this->decodeJsonResponse($response);
    }

    private function invalidator(\Symfony\Component\HttpKernel\KernelInterface $kernel): RpcCacheInvalidator
    {
        $invalidator = $kernel->getContainer()->get(RpcCacheInvalidator::class);
        $this->assertInstanceOf(RpcCacheInvalidator::class, $invalidator);

        return $invalidator;
    }

    /**
     * @return array<string, mixed>
     */
    private function callAsUser(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $method): array
    {
        $body = $this->jsonEncode(['jsonrpc' => '2.0', 'method' => $method, 'id' => 1]);
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TEST_ROLES' => 'ROLE_USER'], content: $body);

        return $this->decodeJsonResponse($kernel->handle($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function callRaw(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $body): array
    {
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $response = $kernel->handle($request);

        return $this->decodeJsonResponse($response);
    }
}
