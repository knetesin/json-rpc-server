<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Cache;

use Knetesin\JsonRpcServerBundle\Attribute\Cache as CacheAttr;
use Knetesin\JsonRpcServerBundle\Controller\RpcController;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Looks up and stores method results in a PSR-6 cache pool. The cache key
 * is composed from the method name, the optional `scope` contributor's
 * value, and a stable hash of the request params (assoc keys sorted, list
 * order preserved).
 *
 * Key forms:
 *   - `{keyPrefix}.{method}.{paramsSha1}` — readable, for methods without a
 *     cache scope whose key fits the PSR-6-safe charset and
 *     `max_readable_key_length`. The fixed prefix and fixed-length suffix
 *     make it unambiguous even though method names contain dots.
 *   - `{hashPrefix}.{sha1}` — everything else, including every scoped entry:
 *     scope keys are arbitrary strings, so a readable join could collide
 *     across methods.
 *
 * Tagging:
 *   - Every stored item is automatically tagged with `rpc.method.{name}`
 *     (dot separator — PSR-6 reserves `:` in tag values).
 *   - User-supplied `#[Rpc\Cache(tags: [...])]` are appended verbatim.
 *   - Tags are only honored when the resolved pool implements
 *     {@see TagAwareAdapterInterface}; otherwise they are silently dropped.
 *
 * **symfony/cache is a soft dep.** Plain PSR-6 pools work out of the box
 * (basic get/set/TTL). Tag-aware invalidation (`purgeMethod`, `purgeTags`)
 * needs `symfony/cache` because `TagAwareAdapterInterface` lives there — on
 * installs without the package these methods return false (the instanceof
 * check evaluates to false when the class is absent, no fatal).
 *
 * Both scopes and pools live in ServiceLocators populated at compile time:
 *   - $scopes      — every contributor referenced by a method's
 *                    `#[Rpc\Cache(scope: ...)]` (plus the built-ins
 *                    UserScope and IpScope).
 *   - $namedPools  — every pool listed under `json_rpc_server.cache.pools`.
 */
final class CacheChecker
{
    /**
     * PSR-6 reserves these chars: {}()/\@: . Plus we also forbid anything outside
     * the printable / readable range so keys stay greppable in Redis-cli, etc.
     *
     * Default 200 chars sits well below common backend limits. Symfony's
     * FilesystemAdapter accepts long keys (it hashes file names internally),
     * Memcached caps at 250 bytes, Redis at 512 MiB. Lower it via
     * `json_rpc_server.cache.max_readable_key_length` for legacy backends with stricter
     * limits, or raise it on backends with generous key budgets.
     */
    private const int DEFAULT_MAX_READABLE_KEY_LEN = 200;
    private const string DEFAULT_KEY_PREFIX = 'rpc.cache';
    private const string DEFAULT_HASH_PREFIX = 'rpc';

    /**
     * Prefix for the automatic per-method cache tag. Built as
     * `rpc.method.{name}`. The `.` separator is required: PSR-6 forbids
     * `{}()/\@:` in tag values, and we want the tag to stay readable.
     */
    public const string METHOD_TAG_PREFIX = 'rpc.method.';

    private readonly int $maxReadableKeyLen;
    private readonly string $keyPrefix;
    private readonly string $hashPrefix;
    private readonly int $jsonFlags;

    public function __construct(
        private readonly CacheItemPoolInterface $defaultPool,
        private readonly ContainerInterface $namedPools,
        private readonly ContainerInterface $scopes,
        ?int $maxReadableKeyLength = null,
        ?string $keyPrefix = null,
        ?string $hashPrefix = null,
        /* Flags the RPC response is encoded with; results they cannot encode are not cached. */
        ?int $jsonEncodeFlags = null,
    ) {
        $this->maxReadableKeyLen = $maxReadableKeyLength ?? self::DEFAULT_MAX_READABLE_KEY_LEN;
        $this->keyPrefix = $keyPrefix ?? self::DEFAULT_KEY_PREFIX;
        $this->hashPrefix = $hashPrefix ?? self::DEFAULT_HASH_PREFIX;
        $this->jsonFlags = ($jsonEncodeFlags ?? RpcController::DEFAULT_JSON_FLAGS) | \JSON_THROW_ON_ERROR;
    }

    public function get(MethodMetadata $method, RpcRequest $request): ?CachedResult
    {
        if (null === $method->cache) {
            return null;
        }
        $pool = $this->resolvePool($method->cache);
        $item = $pool->getItem($this->buildKey($method, $request));
        if (!$item->isHit()) {
            return null;
        }

        return new CachedResult($item->get());
    }

    public function set(MethodMetadata $method, RpcRequest $request, mixed $result): void
    {
        if (null === $method->cache || !$this->isEncodable($result)) {
            return;
        }
        $pool = $this->resolvePool($method->cache);
        $item = $pool->getItem($this->buildKey($method, $request));
        $item->set($result);
        $item->expiresAfter($method->cache->ttl);

        // Tag the item so it can be wiped by tag later. We always add the
        // method-name tag; user-supplied tags get appended. On non-tag-aware
        // pools the tag call is silently absent and items live to TTL.
        if ($pool instanceof TagAwareAdapterInterface && method_exists($item, 'tag')) {
            $item->tag($this->collectTags($method));
        }

        $pool->save($item);
    }

    /**
     * Purges the exact slot for `(method, request params)`. Returns true if
     * the pool reports the deletion as effective.
     *
     * @param string|null $scopeKey the value the method's cache scope produced
     *                              for the entry's owner (e.g. `user:alice`);
     *                              null uses the current caller's scope
     *
     * @throws \InvalidArgumentException when a scope key is given for a method without a cache scope
     */
    public function purgeKey(MethodMetadata $method, RpcRequest $request, ?string $scopeKey = null): bool
    {
        if (null === $method->cache) {
            return false;
        }
        if (null !== $scopeKey && null === $method->cache->scope) {
            throw new \InvalidArgumentException(\sprintf('RPC method %s has no cache scope; purge it without a scope key.', $method->name));
        }

        return $this->resolvePool($method->cache)->deleteItem($this->buildKey($method, $request, $scopeKey));
    }

    /**
     * Purges every cached entry of the given method via tag invalidation.
     * Requires a tag-aware pool — returns false on plain PSR-6 backends.
     */
    public function purgeMethod(MethodMetadata $method): bool
    {
        if (null === $method->cache) {
            return false;
        }
        $pool = $this->resolvePool($method->cache);
        if (!$pool instanceof TagAwareAdapterInterface) {
            return false;
        }

        return $pool->invalidateTags([self::METHOD_TAG_PREFIX.$method->name]);
    }

    /**
     * Purges every cached entry that was stored with any of the given tags.
     * Operates on the default pool unless `pool` is supplied.
     *
     * @param list<string> $tags
     */
    public function purgeTags(array $tags, ?string $pool = null): bool
    {
        if ([] === $tags) {
            return false;
        }
        $resolved = null === $pool ? $this->defaultPool : $this->resolveNamedPool($pool);
        if (!$resolved instanceof TagAwareAdapterInterface) {
            return false;
        }

        return $resolved->invalidateTags($tags);
    }

    /**
     * Wipes the configured pool (or a named pool) outright. Use sparingly —
     * this nukes everything in the pool, not just RPC entries.
     */
    public function purgeAll(?string $pool = null): bool
    {
        $resolved = null === $pool ? $this->defaultPool : $this->resolveNamedPool($pool);

        return $resolved->clear();
    }

    /**
     * @return list<string>
     */
    private function collectTags(MethodMetadata $method): array
    {
        \assert(null !== $method->cache);
        $tags = [self::METHOD_TAG_PREFIX.$method->name];
        foreach ($method->cache->tags as $t) {
            $tags[] = $t;
        }

        return $tags;
    }

    private function resolvePool(CacheAttr $cache): CacheItemPoolInterface
    {
        if (null === $cache->pool) {
            return $this->defaultPool;
        }

        return $this->resolveNamedPool($cache->pool);
    }

    private function resolveNamedPool(string $name): CacheItemPoolInterface
    {
        if (!$this->namedPools->has($name)) {
            throw new \LogicException(\sprintf('Cache pool "%s" is not declared in json_rpc_server.cache.pools. Add it to the bundle config.', $name));
        }

        return $this->namedPools->get($name);
    }

    private function buildKey(MethodMetadata $method, RpcRequest $request, ?string $scopeKey = null): string
    {
        $cache = $method->cache;
        \assert(null !== $cache);
        $paramsHash = $this->paramsHash($request);

        if (null === $cache->scope) {
            $readable = $this->keyPrefix.'.'.$method->name.'.'.$paramsHash;
            if (1 !== preg_match('#[^A-Za-z0-9_.\-]#', $readable) && \strlen($readable) <= $this->maxReadableKeyLen) {
                return $readable;
            }

            return $this->hashedKey([$this->keyPrefix, $method->name, $paramsHash]);
        }

        if (null === $scopeKey) {
            if (!$this->scopes->has($cache->scope)) {
                throw new \LogicException(\sprintf('Cache scope "%s" referenced by method %s is not registered as a service.', $cache->scope, $method->name));
            }
            $scope = $this->scopes->get($cache->scope);
            if (!$scope instanceof CacheScope) {
                throw new \LogicException(\sprintf('"%s" must implement %s.', $cache->scope, CacheScope::class));
            }
            $scopeKey = $scope->key($method, $request);
        }

        return $this->hashedKey([$this->keyPrefix, $method->name, $scopeKey, $paramsHash]);
    }

    /**
     * @param list<string> $parts
     */
    private function hashedKey(array $parts): string
    {
        // serialize() is length-prefixed, so no part can spill into its neighbour.
        return $this->hashPrefix.'.'.sha1(serialize($parts));
    }

    /**
     * The controller answers -32603 for a result it cannot encode; caching it
     * would replay that failure on every hit until the TTL expires.
     */
    private function isEncodable(mixed $result): bool
    {
        try {
            json_encode($result, $this->jsonFlags);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }

    private function paramsHash(RpcRequest $request): string
    {
        $sorted = $this->stableSort($request->params->all(), $request->params->isList());

        return sha1((string) json_encode($sorted, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private function stableSort(mixed $value, bool $isList): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if ($isList) {
            return array_map(fn ($v) => $this->stableSort($v, \is_array($v) && array_is_list($v)), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->stableSort($v, \is_array($v) && array_is_list($v)), $value);
    }
}
