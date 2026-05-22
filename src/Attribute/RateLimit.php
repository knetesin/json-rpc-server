<?php

declare(strict_types=1);

namespace JsonRpcServer\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class RateLimit
{
    public function __construct(
        /**
         * For FixedWindow/SlidingWindow: max successful calls within the window.
         * For TokenBucket: bucket size (also the max instantaneous burst).
         */
        public readonly int $limit,
        /**
         * For FixedWindow/SlidingWindow: window size in seconds.
         * For TokenBucket: time over which `limit` tokens are refilled — so the
         * steady-state rate is `limit / intervalSec` per second.
         */
        public readonly int $intervalSec,
        public readonly RateLimitScope $scope = RateLimitScope::User,
        public readonly RateLimitPolicy $policy = RateLimitPolicy::FixedWindow,
    ) {
        if (RateLimitPolicy::NoLimit !== $policy && ($limit < 1 || $intervalSec < 1)) {
            throw new \InvalidArgumentException('RateLimit#limit and intervalSec must be positive');
        }
    }
}
