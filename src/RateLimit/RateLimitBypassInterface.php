<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\RateLimit;

use Knetesin\JsonRpcServerBundle\Attribute\RateLimit;
use Knetesin\JsonRpcServerBundle\Registry\MethodMetadata;

/**
 * Lets an application exempt selected requests from `#[Rpc\RateLimit]` without
 * replacing the bundle's RateLimitChecker.
 *
 * RateLimitChecker consults every tagged implementation BEFORE consuming a
 * token. The first one to return true short-circuits the check — the method
 * runs as if it had no rate limit. Returning false defers to the next voter
 * (and ultimately to the attribute's normal enforcement).
 *
 * This is bypass-only by design: a voter can lift a limit but cannot tighten
 * one or add a limit where no attribute exists. Typical uses are verified
 * search-engine crawlers (forward-confirmed reverse DNS), internal IP ranges,
 * or platform health checks.
 *
 * Implementations are auto-tagged via `registerForAutoconfiguration` — just
 * implement the interface in a service; no manual tag needed. Keep the check
 * cheap (cache expensive lookups like DNS); it runs on every rate-limited call.
 */
interface RateLimitBypassInterface
{
    /**
     * @return bool true to skip rate limiting for the current request
     */
    public function shouldBypass(MethodMetadata $method, RateLimit $rateLimit): bool;
}
