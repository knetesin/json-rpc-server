<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Batch;

/**
 * No-op budget tracker. Every reserve() succeeds, every release() does nothing.
 *
 * Used when:
 *   - `parallel_batch.budget = 0` (operator explicitly disables the cap), or
 *   - the configured store (e.g. APCu) is unavailable on this host.
 *
 * Strongly **not recommended in production** — without a real budget, a burst
 * of large batches can saturate the worker pool. NullBudgetTracker only relies
 * on the per-batch `max_concurrency` cap, which doesn't see what other parents
 * are doing.
 */
final class NullBudgetTracker implements BudgetTrackerInterface
{
    public function reserve(int $count): bool
    {
        return true;
    }

    public function release(int $count): void
    {
    }

    public function inflight(): int
    {
        return 0;
    }
}
