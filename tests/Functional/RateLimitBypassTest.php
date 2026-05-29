<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Proves the RateLimitBypassInterface extension point is wired end-to-end:
 * an app service implementing the interface is auto-tagged
 * (registerForAutoconfiguration) and collected by RateLimitChecker's
 * tagged_iterator, letting it skip the limit for selected requests.
 *
 * @see \Knetesin\JsonRpcServerBundle\Tests\Fixtures\RateLimit\HeaderBypass
 */
final class RateLimitBypassTest extends KernelTestCase
{
    public function testBypassHeaderSkipsRateLimit(): void
    {
        $kernel = $this->boot();

        // test.throttled is limit=2/GlobalScope — well past it, but the bypass
        // voter opts every one of these out.
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->call($kernel, bypass: true);
            $this->assertSame(['ok' => true], $result['result'], "call $i should bypass the limit");
        }
    }

    public function testLimitStillEnforcedWithoutBypassHeader(): void
    {
        $kernel = $this->boot();

        $this->assertSame(['ok' => true], $this->call($kernel, bypass: false)['result']);
        $this->assertSame(['ok' => true], $this->call($kernel, bypass: false)['result']);

        $third = $this->call($kernel, bypass: false);
        $this->assertSame(-32003, $third['error']['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(KernelInterface $kernel, bool $bypass): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($bypass) {
            $server['HTTP_X_BYPASS_RATELIMIT'] = '1';
        }

        $request = Request::create(
            '/rpc',
            'POST',
            server: $server,
            content: '{"jsonrpc":"2.0","method":"test.throttled","id":1}',
        );
        $response = $kernel->handle($request);

        return $this->decodeJsonResponse($response);
    }
}
