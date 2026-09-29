<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Cache\Scope;

use Knetesin\JsonRpcServerBundle\Cache\CacheScope;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Knetesin\JsonRpcServerBundle\Security\SecurityUserResolver;

/**
 * Built-in scope: one cache slot per Symfony user identifier (key
 * `user:<identifier>`). Unauthenticated callers share the `guest` slot, which
 * no user identifier can collide with.
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
        $user = $this->users->getUser();

        return null === $user ? 'guest' : 'user:'.$user->getUserIdentifier();
    }
}
