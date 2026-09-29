<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\Batch\ParallelBatchExecutor;
use Knetesin\JsonRpcServerBundle\Http\FanoutClientIpSigner;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * End-to-end fan-out: the parent kernel's HTTP client is replaced by a mock
 * that hands every sub-call to a second kernel as a request from 127.0.0.1,
 * which is how a real loopback sub-call arrives.
 */
final class ParallelBatchClientIpTest extends KernelTestCase
{
    private const array PARALLEL = [
        'parallel_batch' => ['enabled' => true, 'self_url' => 'http://localhost/rpc', 'budget_store' => 'null'],
    ];

    private ?TestKernel $subKernel = null;

    protected function tearDown(): void
    {
        LoopbackHttpClientFactory::$target = null;
        $this->subKernel?->shutdown();
        $this->subKernel = null;

        parent::tearDown();
    }

    public function testSubCallsSeeTheOriginalClientIp(): void
    {
        $parent = $this->bootFanout();

        $responses = $this->batch($parent, '203.0.113.7', 'clientip.echo', 'clientip.echo');

        $this->assertSame(['ip' => '203.0.113.7'], $responses[0]['result']);
        $this->assertSame(['ip' => '203.0.113.7'], $responses[1]['result']);
        $this->assertSame(2, LoopbackHttpClientFactory::$subCalls, 'The batch must have been fanned out.');
    }

    public function testIpRateLimitInSubCallsIsPerOriginalClient(): void
    {
        $parent = $this->bootFanout();

        $a = $this->batch($parent, '203.0.113.7', 'clientip.ip_throttled', 'clientip.ip_throttled');
        $b = $this->batch($parent, '198.51.100.9', 'clientip.ip_throttled', 'clientip.ip_throttled');

        $this->assertSame(['ok' => true], $a[0]['result']);
        $this->assertSame(-32003, $a[1]['error']['code']);
        // A second client has its own bucket instead of sharing the loopback one.
        $this->assertSame(['ok' => true], $b[0]['result']);
        $this->assertSame(-32003, $b[1]['error']['code']);
        $this->assertSame(4, LoopbackHttpClientFactory::$subCalls);
    }

    public function testGuestUserRateLimitInSubCallsIsPerOriginalClient(): void
    {
        $parent = $this->bootFanout();

        $a = $this->batch($parent, '203.0.113.7', 'clientip.user_throttled', 'clientip.user_throttled');
        $b = $this->batch($parent, '198.51.100.9', 'clientip.user_throttled', 'clientip.user_throttled');

        $this->assertSame(['ok' => true], $a[0]['result']);
        $this->assertSame(-32003, $a[1]['error']['code']);
        $this->assertSame(['ok' => true], $b[0]['result']);
        $this->assertSame(4, LoopbackHttpClientFactory::$subCalls);
    }

    public function testUnsignedOrBadlySignedClientIpHeaderIsIgnored(): void
    {
        $kernel = $this->boot(self::PARALLEL, ['ClientIp']);
        $body = '{"jsonrpc":"2.0","method":"clientip.echo","id":1}';

        foreach ([null, str_repeat('0', 64)] as $signature) {
            $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.1'], content: $body);
            $request->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '1');
            $request->headers->set(FanoutClientIpSigner::IP_HEADER, '203.0.113.7');
            if (null !== $signature) {
                $request->headers->set(FanoutClientIpSigner::SIGNATURE_HEADER, $signature);
            }

            $payload = json_decode($this->responseContent($kernel->handle($request)), true, 32, \JSON_THROW_ON_ERROR);

            $this->assertSame(['ip' => '192.0.2.1'], $payload['result']);
        }
    }

    public function testClientIpHeaderIsIgnoredWhenParallelBatchIsDisabled(): void
    {
        $kernel = $this->boot([], ['ClientIp']);
        $body = '{"jsonrpc":"2.0","method":"clientip.echo","id":1}';
        $request = Request::create('/rpc', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.1'], content: $body);
        $request->headers->set(ParallelBatchExecutor::DEPTH_HEADER, '1');
        $request->headers->set(FanoutClientIpSigner::IP_HEADER, '203.0.113.7');
        $request->headers->set(FanoutClientIpSigner::SIGNATURE_HEADER, (new FanoutClientIpSigner('test'))->sign('203.0.113.7', 1, $body));

        $payload = json_decode($this->responseContent($kernel->handle($request)), true, 32, \JSON_THROW_ON_ERROR);

        $this->assertSame(['ip' => '192.0.2.1'], $payload['result']);
    }

    private function bootFanout(): TestKernel
    {
        $this->subKernel = new TestKernel(self::PARALLEL, ['ClientIp']);
        $this->subKernel->boot();
        LoopbackHttpClientFactory::$target = $this->subKernel;
        LoopbackHttpClientFactory::$subCalls = 0;

        return $this->boot(self::PARALLEL, ['ClientIp'], [
            ['pass' => new LoopbackHttpClientPass(), 'type' => PassConfig::TYPE_BEFORE_OPTIMIZATION, 'priority' => 0],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batch(TestKernel $kernel, string $clientIp, string ...$methods): array
    {
        $items = [];
        foreach (array_values($methods) as $i => $method) {
            $items[] = ['jsonrpc' => '2.0', 'method' => $method, 'id' => $i + 1];
        }
        $request = Request::create(
            '/rpc',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $clientIp],
            content: json_encode($items, \JSON_THROW_ON_ERROR),
        );

        /** @var list<array<string, mixed>> $responses */
        $responses = json_decode($this->responseContent($kernel->handle($request)), true, 32, \JSON_THROW_ON_ERROR);
        usort($responses, static fn (array $x, array $y): int => $x['id'] <=> $y['id']);

        return $responses;
    }
}

/** @internal */
final class LoopbackHttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition(
            'json_rpc_server.parallel_batch.http_client',
            (new Definition(HttpClientInterface::class))->setFactory([LoopbackHttpClientFactory::class, 'create']),
        );
    }
}

/** @internal */
final class LoopbackHttpClientFactory
{
    public static ?HttpKernelInterface $target = null;

    public static int $subCalls = 0;

    public static function create(): HttpClientInterface
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $target = self::$target ?? throw new \LogicException('No loopback target kernel.');
            ++self::$subCalls;
            $body = $options['body'];
            \assert(\is_string($body));

            $request = Request::create($url, $method, server: ['REMOTE_ADDR' => '127.0.0.1'], content: $body);
            foreach ($options['headers'] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $request->headers->set($name, $value);
            }
            $response = $target->handle($request);

            return new MockResponse((string) $response->getContent(), ['http_code' => $response->getStatusCode()]);
        });
    }
}
