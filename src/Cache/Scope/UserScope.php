<?php

declare(strict_types=1);

namespace JsonRpcServer\Cache\Scope;

use JsonRpcServer\Cache\CacheScope;
use JsonRpcServer\Registry\MethodMetadata;
use JsonRpcServer\Request\RpcRequest;
use JsonRpcServer\Security\SecurityUserResolver;

/**
 * Built-in scope: one cache slot per Symfony user identifier. Anonymous
 * callers share the `anon` slot.
 *
 * Reference it with `#[Rpc\Cache(scope: UserScope::class)]`.
 */
final readonly class UserScope implements CacheScope
{
    public function __construct(private readonly SecurityUserResolver $users)
    {
    }

    public function key(MethodMetadata $method, RpcRequest $request): string
    {
        return 'user:'.$this->users->getUserIdentifier();
    }
}
