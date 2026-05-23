<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

/**
 * Picks the algorithm RateLimitChecker hands to symfony/rate-limiter.
 *
 * - FixedWindow: cheap, the default. Counter resets every `intervalSec`.
 *   Edge effect: a client can fire `2 * limit` requests across the window
 *   boundary (last second of window N + first second of N+1).
 *
 * - SlidingWindow: weights the previous window proportionally, smoothing the
 *   edge spike. Slightly more storage per key than FixedWindow.
 *
 * - TokenBucket: bucket of `limit` tokens, refilled `limit / intervalSec` per
 *   second. Lets a caller burst up to `limit` calls instantly when idle, then
 *   throttles to steady-state. Use for human/UI traffic where bursts feel
 *   natural and steady-rate caps feel artificial.
 *
 * - NoLimit: opt out entirely — useful when you want to keep the `#[RateLimit]`
 *   attribute around (e.g., as documentation) but disable enforcement in a
 *   specific env, or in tests.
 */
enum RateLimitPolicy: string
{
    case FixedWindow = 'fixed_window';
    case SlidingWindow = 'sliding_window';
    case TokenBucket = 'token_bucket';
    case NoLimit = 'no_limit';
}
