<?php

declare(strict_types=1);

namespace JsonRpcServer\Batch;

/**
 * System-wide concurrency budget for parallel-batch fan-out.
 *
 * One pessimistic check-and-reserve before each fan-out: ask `reserve($n)` —
 * implementation atomically bumps an inflight counter, returns false if doing
 * so would cross the configured ceiling. On false the caller falls back to
 * sequential dispatch. On true the caller MUST eventually call `release($n)`.
 *
 * Implementations may track:
 *   - per-process state (NullBudgetTracker — no actual cap),
 *   - shared memory (ApcuBudgetTracker — across FPM children of the same host),
 *   - cluster-wide via Redis / etcd (user implementations).
 */
interface BudgetTrackerInterface
{
    /**
     * Tries to reserve $count slots. Returns true if reserved (caller must
     * release the same $count), false if the reservation would exceed the
     * configured budget (caller falls back to sequential).
     */
    public function reserve(int $count): bool;

    /**
     * Returns reserved slots back to the pool. Always paired with a successful
     * `reserve($count)` — implementations are free to no-op on over-release.
     */
    public function release(int $count): void;

    /**
     * Current in-flight count. Used by metrics — semantically "approximate"
     * because the value can change between read and use.
     */
    public function inflight(): int;
}
