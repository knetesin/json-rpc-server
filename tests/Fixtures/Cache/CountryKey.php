<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\Cache;

use Knetesin\JsonRpcServerBundle\Cache\CacheScope;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;
use Knetesin\JsonRpcServerBundle\Request\RpcRequest;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Test contributor that reads the country from an X-Country header — used
 * by CounterVary to prove per-country cache partitioning works.
 */
final class CountryKey implements CacheScope
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function key(MethodMetadata $method, RpcRequest $request): string
    {
        $country = $this->requestStack->getMainRequest()?->headers->get('X-Country') ?? 'XX';

        return 'country:'.$country;
    }
}
