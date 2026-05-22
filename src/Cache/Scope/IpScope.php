<?php

declare(strict_types=1);

namespace JsonRpcServer\Cache\Scope;

use JsonRpcServer\Cache\CacheScope;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcRequest;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Built-in scope: one cache slot per client IP. Falls back to `unknown`
 * when no HTTP request is active or the IP cannot be determined.
 *
 * Reference it with `#[Rpc\Cache(scope: IpScope::class)]`.
 */
final readonly class IpScope implements CacheScope
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function key(MethodMetadata $method, RpcRequest $request): string
    {
        return 'ip:'.($this->requestStack->getMainRequest()?->getClientIp() ?? 'unknown');
    }
}
