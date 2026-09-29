<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Functional;

use Knetesin\JsonRpcServerBundle\DependencyInjection\Compiler\MethodGuardPass;
use Knetesin\JsonRpcServerBundle\Event\MethodInvocationFailedEvent;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\Guard\GroupGuard;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods\CountedGroupRef;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods\GroupCachedStats;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardMethods\GroupFeed;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupAccessDeniedException;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\GroupPermission;
use Knetesin\JsonRpcServerBundle\Tests\Fixtures\GuardSupport\RequiresGroup;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * MethodGuardInterface end-to-end: guards are autoconfigured, see resolved
 * arguments and handler attributes, and run on every entry point before the
 * cache and the handler.
 *
 * @see GroupGuard
 * @see \Knetesin\JsonRpcServerBundle\Tests\Fixtures\Guard\HeaderGuard
 */
final class MethodGuardTest extends KernelTestCase
{
    private const array WITH_GUARDS = ['Guard', 'GuardMethods'];

    protected function setUp(): void
    {
        parent::setUp();

        GroupGuard::$calls = [];
        GroupCachedStats::$invocations = 0;
        GroupFeed::$invoked = false;
        CountedGroupRef::$constructed = 0;
    }

    public function testSingleCallAllowedAndDenied(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $allowed = $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 1], ['X-Allowed-Groups' => '3, 7']);
        $this->assertSame(['groupId' => 7], $allowed['result']);

        $denied = $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 2], ['X-Allowed-Groups' => '1,2']);
        $this->assertSame([
            'code' => GroupAccessDeniedException::CODE,
            'message' => 'Group access denied',
            'data' => ['groupId' => 7, 'permission' => 'View'],
        ], $denied['error']);
        $this->assertSame(2, $denied['id']);
        $this->assertSame(['guard.groupShow', 'guard.groupShow'], GroupGuard::$calls);
    }

    public function testMethodWithoutAttributeIsNotRestricted(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $payload = $this->rpc($kernel, ['method' => 'guard.ping', 'id' => 1]);

        $this->assertSame('pong', $payload['result']);
        $this->assertSame(['guard.ping'], GroupGuard::$calls);
    }

    public function testBatchItemsAreJudgedIndependently(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $payload = $this->rpc($kernel, [
            ['method' => 'guard.groupShow', 'params' => ['groupId' => 8], 'id' => 1],
            ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 2],
        ], ['X-Allowed-Groups' => '7']);

        $this->assertCount(2, $payload);
        $this->assertIsArray($payload[0]);
        $this->assertIsArray($payload[1]);
        $this->assertSame(1, $payload[0]['id']);
        $this->assertSame(-32002, $payload[0]['error']['code']);
        $this->assertSame(['groupId' => 8, 'permission' => 'View'], $payload[0]['error']['data']);
        $this->assertSame(2, $payload[1]['id']);
        $this->assertSame(['groupId' => 7], $payload[1]['result']);
    }

    public function testDeniedNotificationRunsGuardAndReturnsNoBody(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $response = $this->send($kernel, '/rpc', ['method' => 'guard.groupShow', 'params' => ['groupId' => 7]]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame(['guard.groupShow'], GroupGuard::$calls);
    }

    public function testCacheHitDoesNotBypassGuard(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);
        $call = ['method' => 'guard.groupCachedStats', 'params' => ['groupId' => 5], 'id' => 1];

        $first = $this->rpc($kernel, $call, ['X-Allowed-Groups' => '5']);
        $this->assertSame(['groupId' => 5, 'members' => 3], $first['result']);
        $this->assertSame(1, GroupCachedStats::$invocations);

        // Same params, so the slot is warm — the guard must still deny.
        $denied = $this->rpc($kernel, $call);
        $this->assertSame(-32002, $denied['error']['code']);

        $cached = $this->rpc($kernel, $call, ['X-Allowed-Groups' => '5']);
        $this->assertSame(['groupId' => 5, 'members' => 3], $cached['result']);
        $this->assertSame(1, GroupCachedStats::$invocations);
        $this->assertCount(3, GroupGuard::$calls);
    }

    public function testDeniedStreamReturnsEnvelopeWithoutInvokingHandler(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $response = $this->send($kernel, '/rpc/stream', ['method' => 'guard.groupFeed', 'params' => ['groupId' => 4], 'id' => 9]);

        $this->assertNotInstanceOf(StreamedResponse::class, $response);
        // -32002 (NotFoundException) maps to 404 on the stream endpoint.
        $this->assertSame(404, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertSame(9, $payload['id']);
        $this->assertSame(-32002, $payload['error']['code']);
        $this->assertSame(['groupId' => 4, 'permission' => 'View'], $payload['error']['data']);
        $this->assertFalse(GroupFeed::$invoked);
    }

    public function testAllowedStreamYieldsRows(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $response = $this->send($kernel, '/rpc/stream', ['method' => 'guard.groupFeed', 'params' => ['groupId' => 4], 'id' => 9], ['X-Allowed-Groups' => '4']);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $lines = array_values(array_filter(explode("\n", $this->captureStreamBody($response)), static fn (string $l): bool => '' !== $l));
        $this->assertSame(
            [['groupId' => 4, 'n' => 1], ['groupId' => 4, 'n' => 2]],
            array_map(static fn (string $l): mixed => json_decode($l, true, 8, \JSON_THROW_ON_ERROR), $lines),
        );
        $this->assertTrue(GroupFeed::$invoked);
    }

    public function testMcpCallDeniedReturnsToolError(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $response = $this->send($kernel, '/mcp/call', ['name' => 'guard.groupRename', 'arguments' => ['groupId' => 6, 'name' => 'Ops']], jsonRpc: false);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $this->decodeJsonResponse($response);
        $this->assertTrue($payload['isError']);
        $this->assertSame(-32002, $payload['error']['code']);
        $this->assertSame(['groupId' => 6, 'permission' => 'Edit'], $payload['error']['data']);
        $this->assertSame(['guard.groupRename'], GroupGuard::$calls);
    }

    public function testMcpCallAllowed(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $response = $this->send($kernel, '/mcp/call', ['name' => 'guard.groupRename', 'arguments' => ['groupId' => 6, 'name' => 'Ops']], ['X-Allowed-Groups' => '6'], jsonRpc: false);

        $payload = $this->decodeJsonResponse($response);
        $this->assertArrayNotHasKey('isError', $payload);
        $this->assertSame(['groupId' => 6, 'name' => 'Ops'], $payload['structuredContent']);
    }

    public function testHigherPriorityGuardRunsFirst(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $payload = $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 1], ['X-Block' => '1']);

        $this->assertSame(-32010, $payload['error']['code']);
        $this->assertSame('Blocked by header', $payload['error']['message']);
        $this->assertSame([], GroupGuard::$calls);
    }

    public function testCrashingGuardOnRpcBecomesInternalError(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $payload = $this->rpc($kernel, ['method' => 'guard.ping', 'id' => 1], ['X-Guard-Crash' => '1']);

        $this->assertSame(-32603, $payload['error']['code']);
        $this->assertStringNotContainsString('Guard crashed', $this->jsonEncode($payload));
    }

    public function testCrashingGuardOnStreamReturnsJsonEnvelope(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $response = $this->send($kernel, '/rpc/stream', ['method' => 'guard.groupFeed', 'params' => ['groupId' => 4], 'id' => 3], ['X-Allowed-Groups' => '4', 'X-Guard-Crash' => '1']);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $payload = $this->decodeJsonResponse($response);
        $this->assertSame('2.0', $payload['jsonrpc']);
        $this->assertSame(3, $payload['id']);
        $this->assertSame(-32603, $payload['error']['code']);
        $this->assertFalse(GroupFeed::$invoked);
    }

    public function testDenialFiresFailedEvent(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);
        /** @var EventDispatcherInterface $bus */
        $bus = $kernel->getContainer()->get('event_dispatcher');
        /** @var list<MethodInvocationFailedEvent> $failed */
        $failed = [];
        $bus->addListener(MethodInvocationFailedEvent::class, static function (MethodInvocationFailedEvent $e) use (&$failed): void {
            $failed[] = $e;
        });

        $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 1]);

        $this->assertCount(1, $failed);
        $this->assertSame('guard.groupShow', $failed[0]->method->name);
        $this->assertInstanceOf(GroupAccessDeniedException::class, $failed[0]->exception);
    }

    public function testEnumAttributeArgumentSurvivesDumpedContainer(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $requirements = $this->registry($kernel)->get('guard.groupShow')->getAttributes(RequiresGroup::class);

        $this->assertCount(1, $requirements);
        $this->assertSame(GroupPermission::View, $requirements[0]->permission);
        $this->assertSame('groupId', $requirements[0]->on);
    }

    public function testWithoutGuardsNothingIsDeniedAndNoAttributesAreCollected(): void
    {
        $kernel = $this->boot([], ['GuardMethods']);

        $payload = $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 1]);
        $this->assertSame(['groupId' => 7], $payload['result']);

        $response = $this->send($kernel, '/rpc/stream', ['method' => 'guard.groupFeed', 'params' => ['groupId' => 4], 'id' => 2]);
        $this->assertInstanceOf(StreamedResponse::class, $response);

        $this->assertSame([], $this->registry($kernel)->get('guard.groupShow')->attributes);
        $this->assertSame([], GroupGuard::$calls);
    }

    public function testGuardTaggedByLateCompilerPassSeesHandlerAttributes(): void
    {
        // Same slot as a Kernel implementing CompilerPassInterface (Kernel::process()).
        $lateTagging = new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setDefinition(GroupGuard::class, (new Definition(GroupGuard::class))
                    ->setAutowired(true)
                    ->addTag(MethodGuardPass::TAG));
            }
        };
        $kernel = $this->boot([], ['GuardMethods'], [
            ['pass' => $lateTagging, 'type' => PassConfig::TYPE_BEFORE_OPTIMIZATION, 'priority' => -10000],
        ]);

        $denied = $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 1]);
        $this->assertSame(-32002, $denied['error']['code']);
        $this->assertSame(['groupId' => 7, 'permission' => 'View'], $denied['error']['data']);

        $allowed = $this->rpc($kernel, ['method' => 'guard.groupShow', 'params' => ['groupId' => 7], 'id' => 2], ['X-Allowed-Groups' => '7']);
        $this->assertSame(['groupId' => 7], $allowed['result']);
        $this->assertSame(['guard.groupShow', 'guard.groupShow'], GroupGuard::$calls);
    }

    public function testPositionalParamsReachGuardKeyedByParameterName(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);

        $allowed = $this->rpc($kernel, ['method' => 'guard.groupRename', 'params' => [6, 'Ops'], 'id' => 1], ['X-Allowed-Groups' => '6']);
        $this->assertSame(['groupId' => 6, 'name' => 'Ops'], $allowed['result']);

        $denied = $this->rpc($kernel, ['method' => 'guard.groupRename', 'params' => [6, 'Ops'], 'id' => 2], ['X-Allowed-Groups' => '1']);
        $this->assertSame(-32002, $denied['error']['code']);
        $this->assertSame(['groupId' => 6, 'permission' => 'Edit'], $denied['error']['data']);
    }

    public function testWithoutGuardsCacheHitSkipsArgumentResolution(): void
    {
        $kernel = $this->boot([], ['GuardMethods']);
        $call = ['method' => 'guard.groupCachedCount', 'params' => ['groupId' => 5], 'id' => 1];

        $miss = $this->rpc($kernel, $call);
        $hit = $this->rpc($kernel, $call);

        $this->assertSame(['groupId' => 5], $miss['result']);
        $this->assertSame(['groupId' => 5], $hit['result']);
        $this->assertSame(1, CountedGroupRef::$constructed);
    }

    public function testWithGuardsArgumentsAreResolvedBeforeEveryCacheLookup(): void
    {
        $kernel = $this->boot([], self::WITH_GUARDS);
        $call = ['method' => 'guard.groupCachedCount', 'params' => ['groupId' => 5], 'id' => 1];

        $miss = $this->rpc($kernel, $call, ['X-Allowed-Groups' => '5']);
        $hit = $this->rpc($kernel, $call, ['X-Allowed-Groups' => '5']);

        $this->assertSame(['groupId' => 5], $miss['result']);
        $this->assertSame(['groupId' => 5], $hit['result']);
        $this->assertSame(2, CountedGroupRef::$constructed);
        $this->assertSame(['guard.groupCachedCount', 'guard.groupCachedCount'], GroupGuard::$calls);
    }

    private function registry(KernelInterface $kernel): MethodRegistry
    {
        $testContainer = $kernel->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $testContainer);
        $registry = $testContainer->get(MethodRegistry::class);
        $this->assertInstanceOf(MethodRegistry::class, $registry);

        return $registry;
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     *
     * @return array<array-key, mixed>
     */
    private function rpc(KernelInterface $kernel, array $body, array $headers = []): array
    {
        $decoded = json_decode($this->responseContent($this->send($kernel, '/rpc', $body, $headers)), true, 32, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $body a single call, or a list of calls for a batch
     * @param array<string, string> $headers
     */
    private function send(KernelInterface $kernel, string $path, array $body, array $headers = [], bool $jsonRpc = true): Response
    {
        if ($jsonRpc) {
            $body = array_is_list($body)
                ? array_map(static fn (mixed $call): mixed => \is_array($call) ? ['jsonrpc' => '2.0'] + $call : $call, $body)
                : ['jsonrpc' => '2.0'] + $body;
        }

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $kernel->handle(Request::create($path, 'POST', server: $server, content: $this->jsonEncode($body)));
    }
}
