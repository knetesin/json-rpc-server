<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Cache;

use Knetesin\JsonRpcServerBundle\Cache\CacheChecker;
use Knetesin\JsonRpcServerBundle\Cache\CacheScope;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Registry\MethodRegistry;
use Knetesin\JsonRpcServerBundle\Request\RpcParams;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Tests\Unit\Profiler\ProfilerTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CacheCheckerTest extends TestCase
{
    private const string SCOPE = 'test.scope';

    private string $currentScopeKey = 'owner:current';

    public function testUnscopedMethodGetsReadableKey(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool);

        $checker->set($this->method('user.get'), $this->request('user.get', ['id' => 7]), ['name' => 'a']);

        $this->assertSame(['rpc.cache.user.get.'.sha1('{"id":7}')], array_keys($pool->getValues()));
    }

    public function testScopedMethodGetsHashedKey(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool);

        $checker->set($this->method('user.get', scoped: true), $this->request('user.get', ['id' => 7]), ['name' => 'a']);

        $this->assertSame(['rpc.'.sha1(serialize(['rpc.cache', 'user.get', 'owner:current', sha1('{"id":7}')]))], array_keys($pool->getValues()));
    }

    public function testKeyOverReadableLimitIsHashed(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool, maxReadableKeyLength: 20);

        $checker->set($this->method('user.get'), $this->request('user.get', ['id' => 7]), ['name' => 'a']);

        $this->assertSame(['rpc.'.sha1(serialize(['rpc.cache', 'user.get', sha1('{"id":7}')]))], array_keys($pool->getValues()));
    }

    public function testDottedMethodDoesNotCollideWithScopedMethod(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool);
        $this->currentScopeKey = 'b';
        $unscoped = $this->method('a.b');
        $scoped = $this->method('a', scoped: true);

        $checker->set($unscoped, $this->request('a.b'), 'from a.b');
        $checker->set($scoped, $this->request('a'), 'from a');

        $this->assertSame('from a.b', $checker->get($unscoped, $this->request('a.b'))?->value);
        $this->assertSame('from a', $checker->get($scoped, $this->request('a'))?->value);
    }

    public function testHashedKeyPartsCannotShiftIntoEachOther(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool);
        $this->currentScopeKey = 'b';
        $unscoped = $this->method('a|b');   // "|" is outside the readable charset: hashed
        $scoped = $this->method('a', scoped: true);

        $checker->set($unscoped, $this->request('a|b'), 'from a|b');
        $checker->set($scoped, $this->request('a'), 'from a');

        $this->assertSame('from a|b', $checker->get($unscoped, $this->request('a|b'))?->value);
        $this->assertSame('from a', $checker->get($scoped, $this->request('a'))?->value);
    }

    public function testPurgeKeyUsesExplicitScopeKey(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool);
        $method = $this->method('user.get', scoped: true);
        $request = $this->request('user.get');

        $this->currentScopeKey = 'owner:alice';
        $checker->set($method, $request, 'alice');
        $this->currentScopeKey = 'owner:admin';

        $checker->purgeKey($method, $request);
        $this->currentScopeKey = 'owner:alice';
        $this->assertNotNull($checker->get($method, $request), 'without a scope key the caller\'s own slot is purged');

        $this->currentScopeKey = 'owner:admin';
        $checker->purgeKey($method, $request, 'owner:alice');
        $this->currentScopeKey = 'owner:alice';
        $this->assertNull($checker->get($method, $request));
    }

    public function testPurgeKeyRejectsScopeKeyForUnscopedMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->checker(new ArrayAdapter())->purgeKey($this->method('user.get'), $this->request('user.get'), 'owner:alice');
    }

    public function testPurgeKeyAndTagsMatchReadableKeys(): void
    {
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $checker = $this->checker($pool);
        $method = $this->method('user.get');
        $one = $this->request('user.get', ['id' => 1]);
        $two = $this->request('user.get', ['id' => 2]);

        $checker->set($method, $one, 'one');
        $checker->set($method, $two, 'two');
        $checker->purgeKey($method, $one);
        $this->assertNull($checker->get($method, $one));
        $this->assertSame('two', $checker->get($method, $two)?->value);

        $checker->purgeMethod($method);
        $this->assertNull($checker->get($method, $two));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unencodableResults(): iterable
    {
        yield 'invalid UTF-8' => [['label' => "caf\xE9"]];
        yield 'NAN' => [\NAN];
        yield 'INF' => [['ratio' => \INF]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unencodableResults')]
    public function testUnencodableResultIsNotCached(mixed $result): void
    {
        $pool = new ArrayAdapter();

        $this->checker($pool)->set($this->method('user.get'), $this->request('user.get'), $result);

        $this->assertSame([], $pool->getValues());
    }

    public function testPartialOutputFlagMakesResultCacheable(): void
    {
        $pool = new ArrayAdapter();
        $checker = $this->checker($pool, jsonEncodeFlags: \JSON_PARTIAL_OUTPUT_ON_ERROR);

        $checker->set($this->method('user.get'), $this->request('user.get'), "caf\xE9");

        $this->assertCount(1, $pool->getValues());
    }

    private function checker(ArrayAdapter|TagAwareAdapter $pool, ?int $maxReadableKeyLength = null, ?int $jsonEncodeFlags = null): CacheChecker
    {
        $scope = new class($this) implements CacheScope {
            public function __construct(private readonly CacheCheckerTest $test)
            {
            }

            public function key(MethodMetadata $method, RpcRequest $request): string
            {
                return $this->test->currentScopeKey();
            }
        };

        return new CacheChecker(
            $pool,
            new ServiceLocator([]),
            new ServiceLocator([self::SCOPE => static fn (): CacheScope => $scope]),
            $maxReadableKeyLength,
            jsonEncodeFlags: $jsonEncodeFlags,
        );
    }

    public function currentScopeKey(): string
    {
        return $this->currentScopeKey;
    }

    private function method(string $name, bool $scoped = false): MethodMetadata
    {
        $raw = ProfilerTestHelper::rawMethod($name);
        $raw['cache'] = ['ttl' => 60, 'scope' => $scoped ? self::SCOPE : null, 'pool' => null, 'tags' => []];

        return (new MethodRegistry([$name => $raw], $this->createStub(ContainerInterface::class)))->get($name);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function request(string $method, ?array $params = null): RpcRequest
    {
        return new RpcRequest(id: 1, method: $method, params: new RpcParams($params), isNotification: false);
    }
}
