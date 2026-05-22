<?php

declare(strict_types=1);

namespace JsonRpcServer\Cache;

use JsonRpcServer\Exception\MethodNotFoundException;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Registry\MethodRegistry;
use JsonRpcServer\Request\RpcParams;
use JsonRpcServer\Request\RpcRequest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Public-facing cache control. Application code should depend on this service
 * (not {@see CacheChecker}) when it needs to drop entries — it gives an
 * API that mirrors the kind of question business code actually asks:
 *
 *   - "user.update just ran; drop user.get for that user."
 *   - "user 42 logged out; flush everything keyed under that user."
 *   - "background job re-indexed; nuke this whole method's cache."
 *
 * Every purge is logged at info level so audit / debugging can answer
 * "who/what cleared this cache slot and when". Inject a real logger
 * (Monolog channel `rpc`) to capture it in production.
 *
 * `purgeMethod` and `purgeTags` require a tag-aware pool (Symfony's
 * `cache.adapter.tag_aware` wrapper or any pool implementing
 * {@see \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface}). On
 * plain PSR-6 backends these methods return false — fall back to per-key
 * `purge` or set sensible TTLs.
 */
final class RpcCacheInvalidator
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MethodRegistry $registry,
        private readonly CacheChecker $cache,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Drops the single cache entry for `(method, params)`. Use this from
     * write-side handlers right after they mutate the underlying data.
     *
     * @param array<array-key, mixed>|null $params null clears the no-args slot;
     *                                             list/assoc work the same as
     *                                             the actual RPC call would
     */
    public function purge(string $method, ?array $params = null): bool
    {
        $meta = $this->safeGet($method);
        if (null === $meta) {
            $this->logger->info('RPC cache purge skipped: unknown method', ['method' => $method]);

            return false;
        }
        $request = new RpcRequest(
            id: null,
            method: $method,
            params: new RpcParams($params),
            isNotification: false,
        );

        $ok = $this->cache->purgeKey($meta, $request);
        $this->logger->info('RPC cache purge', [
            'mode' => 'key',
            'method' => $method,
            'ok' => $ok,
        ]);

        return $ok;
    }

    /**
     * Drops every cached entry of the named method via tag invalidation.
     */
    public function purgeMethod(string $method): bool
    {
        $meta = $this->safeGet($method);
        if (null === $meta) {
            $this->logger->info('RPC cache purge skipped: unknown method', ['method' => $method]);

            return false;
        }

        $ok = $this->cache->purgeMethod($meta);
        $this->logger->info('RPC cache purge', [
            'mode' => 'method',
            'method' => $method,
            'ok' => $ok,
        ]);

        return $ok;
    }

    /**
     * Drops every cached entry stamped with any of the given tags. Use this
     * to clear cross-method caches that share a custom tag — e.g. all
     * "user:42" entries across several methods.
     *
     * @param list<string> $tags
     */
    public function purgeTags(array $tags, ?string $pool = null): bool
    {
        $ok = $this->cache->purgeTags($tags, $pool);
        $this->logger->info('RPC cache purge', [
            'mode' => 'tags',
            'tags' => $tags,
            'pool' => $pool,
            'ok' => $ok,
        ]);

        return $ok;
    }

    /**
     * Wipes the entire pool — for "clear all RPC caches" CLI flows.
     */
    public function purgeAll(?string $pool = null): bool
    {
        $ok = $this->cache->purgeAll($pool);
        $this->logger->info('RPC cache purge', [
            'mode' => 'all',
            'pool' => $pool,
            'ok' => $ok,
        ]);

        return $ok;
    }

    private function safeGet(string $method): ?MethodMetadata
    {
        try {
            return $this->registry->get($method);
        } catch (MethodNotFoundException) {
            return null;
        }
    }
}
