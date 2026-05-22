<?php

declare(strict_types=1);

namespace JsonRpcServer\Batch;

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
 * Failure mode: if APCu isn't compiled / enabled in the running PHP, all
 * methods degrade to a NullBudgetTracker equivalent rather than throwing.
 * RpcExtension swaps the service definition at compile time when APCu is
 * absent — this class assumes it's there.
 */
final class ApcuBudgetTracker implements BudgetTrackerInterface
{
    /** APCu key for the inflight counter. Single key, no per-method buckets. */
    private const string KEY = 'json_rpc_server.parallel_batch.inflight';

    public function __construct(
        private readonly int $budget,
    ) {
    }

    public function reserve(int $count): bool
    {
        if ($count <= 0) {
            return true;
        }

        // apcu_inc atomically bumps and returns the post-increment value.
        // Race-free across processes; the worst case is a momentary overshoot
        // that we immediately roll back below.
        $after = apcu_inc(self::KEY, $count, $success);
        if (false === $success || !\is_int($after)) {
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
        if ($count <= 0) {
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
        $value = apcu_fetch(self::KEY, $success);
        if (false === $success || !\is_int($value)) {
            return 0;
        }

        return max(0, $value);
    }

    public static function isAvailable(): bool
    {
        if (!\function_exists('apcu_inc') || !\function_exists('apcu_dec') || !\function_exists('apcu_fetch')) {
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
