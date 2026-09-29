<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Fixtures\CacheEncoding;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;

/** Cached method whose data source can be switched to return invalid UTF-8. */
#[Rpc\Method('cache.label')]
#[Rpc\Cache(ttl: 60)]
final class CachedLabel
{
    public static bool $broken = false;

    public function __invoke(): string
    {
        return self::$broken ? "caf\xE9" : 'café';
    }
}
