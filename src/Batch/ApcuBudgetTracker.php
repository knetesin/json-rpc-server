<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Batch;

use Psr\Log\LoggerInterface;

/**
 * APCu-backed system-wide budget tracker. Lock-free CAS / inc / dec primitives
 * give us a shared counter visible to every PHP process on the host running
 * under the same APCu instance — i.e. the entire FPM children pool.
 *
 * NOT cluster-wide. If you have multiple application servers behind a load
 * balancer, each one tracks its own budget independently. For a global
 * cluster budget, swap in a Redis-backed tracker (user implementation
 * implementing {@see BudgetTrackerInterface}).
 *
 * Failure mode: availability is checked at runtime, in the SAPI that serves
 * the request (the container may be built by a CLI without APCu). When APCu
 * is missing, disabled or refuses the write, reserve() returns false — the
 * batch runs sequentially, never uncapped — and a warning is logged once per
 * process. release() and inflight() are no-ops then.
 */
final class ApcuBudgetTracker implements BudgetTrackerInterface
{
    /** APCu key for the inflight counter. Single key, no per-method buckets. */
    private const string KEY = 'json_rpc_server.parallel_batch.inflight';

    private static bool $unavailableWarned = false;

    public function __construct(
        private readonly int $budget,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function reserve(int $count): bool
    {
        if ($count <= 0) {
            return true;
        }

        if (!self::functionsUsable()) {
            $this->warnUnavailable('APCu is not loaded or not enabled in this SAPI');

            return false;
        }

        // apcu_inc atomically bumps and returns the post-increment value.
        // Race-free across processes; the worst case is a momentary overshoot
        // that we immediately roll back below.
        $after = apcu_inc(self::KEY, $count, $success);
        if (false === $success || !\is_int($after)) {
            $this->warnUnavailable('apcu_inc() failed');

            return false;
        }

        if ($after > $this->budget) {
            // Reservation would breach the budget — roll back and let the
            // caller fall back to sequential.
            apcu_dec(self::KEY, $count);

            return false;
        }

        return true;
    }

    public function release(int $count): void
    {
        if ($count <= 0 || !self::functionsUsable()) {
            return;
        }
        // apcu_dec is also lock-free. We deliberately do not assert that the
        // result stays non-negative — APCu segments can be flushed under us
        // (rolling restart, opcache_reset on a misconfigured deploy), and
        // refusing to release would only make the budget hole permanent.
        apcu_dec(self::KEY, $count);
    }

    public function inflight(): int
    {
        if (!self::functionsUsable()) {
            return 0;
        }

        $value = apcu_fetch(self::KEY, $success);
        if (false === $success || !\is_int($value)) {
            return 0;
        }

        return max(0, $value);
    }

    /**
     * Full probe including a test write. Not used on the request path, which
     * relies on {@see reserve()} failing instead.
     */
    public static function isAvailable(): bool
    {
        if (!self::functionsUsable()) {
            return false;
        }

        if (!self::iniFlag('apc.enabled')) {
            return false;
        }

        // Extension may be loaded (e.g. GitHub Actions) but APCu refuses CLI
        // writes unless apc.enable_cli=1 — reserve() would always return false.
        if ('cli' === \PHP_SAPI && !self::iniFlag('apc.enable_cli')) {
            return false;
        }

        return self::probeWritable();
    }

    /** apcu_enabled() covers apc.enabled and, on CLI, apc.enable_cli. */
    private static function functionsUsable(): bool
    {
        return \function_exists('apcu_enabled')
            && \function_exists('apcu_inc')
            && \function_exists('apcu_dec')
            && \function_exists('apcu_fetch')
            && apcu_enabled();
    }

    private function warnUnavailable(string $reason): void
    {
        if (self::$unavailableWarned) {
            return;
        }
        self::$unavailableWarned = true;

        $this->logger?->warning('json_rpc_server: parallel_batch.budget_store="apcu" but APCu is unusable ({reason}); batches run sequentially. Enable APCu for this SAPI, or set budget_store: null to fan out without a system-wide cap.', [
            'reason' => $reason,
        ]);
    }

    private static function iniFlag(string $key): bool
    {
        $value = \ini_get($key);

        return false !== $value && '' !== $value && filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /** One atomic inc to verify APCu shared memory is writable in this SAPI. */
    private static function probeWritable(): bool
    {
        $probe = '__json_rpc_server_apcu_probe__';
        if (\function_exists('apcu_delete')) {
            apcu_delete($probe);
        }

        $after = apcu_inc($probe, 1, $success);
        if (false === $success || !\is_int($after) || 1 !== $after) {
            return false;
        }

        if (\function_exists('apcu_delete')) {
            apcu_delete($probe);
        }

        return true;
    }
}
