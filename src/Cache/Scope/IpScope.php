<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Cache\Scope;

use Knetesin\JsonRpcServerBundle\Cache\CacheScope;
use Knetesin\JsonRpcServerBundle\Http\ClientIpResolver;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Built-in scope: one cache slot per client IP. Falls back to `unknown`
 * when no HTTP request is active or the IP cannot be determined. Parallel
 * batch sub-calls use the original client IP via {@see ClientIpResolver}.
 *
 * Reference it with `#[Rpc\Cache(scope: IpScope::class)]`.
 */
final readonly class IpScope implements CacheScope
{
    public function __construct(
        private RequestStack $requestStack,
        private ?ClientIpResolver $clientIps = null,
    ) {
    }

    public function key(MethodMetadata $method, RpcRequest $request): string
    {
        $ip = null !== $this->clientIps
            ? $this->clientIps->clientIp()
            : $this->requestStack->getMainRequest()?->getClientIp();

        return 'ip:'.($ip ?? 'unknown');
    }
}
