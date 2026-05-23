<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Batch;

use Knetesin\JsonRpcServerBundle\Batch\ApcuBudgetTracker;
use PHPUnit\Framework\TestCase;

final class ApcuBudgetTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ApcuBudgetTracker::isAvailable()) {
            self::markTestSkipped(
                'APCu not usable in this PHP runtime (missing extension, apc.enabled=0, apc.enable_cli=0 on CLI, or shared memory unavailable).',
            );
        }
        // Fresh counter for every test.
        apcu_clear_cache();
    }

    public function testReserveSucceedsBelowBudget(): void
    {
        $tracker = new ApcuBudgetTracker(budget: 10);

        $this->assertTrue($tracker->reserve(3));
        $this->assertSame(3, $tracker->inflight());

        $this->assertTrue($tracker->reserve(7));
        $this->assertSame(10, $tracker->inflight());
    }

    public function testReserveFailsWhenItWouldExceedBudgetAndRollsBack(): void
    {
        $tracker = new ApcuBudgetTracker(budget: 10);

        $this->assertTrue($tracker->reserve(8));
        // Asking for 5 more would total 13 > 10 — must be rejected, and
        // inflight must roll back to 8 (not stick at 13).
        $this->assertFalse($tracker->reserve(5));
        $this->assertSame(8, $tracker->inflight());

        // After the rollback, reserving the remaining 2 still works.
        $this->assertTrue($tracker->reserve(2));
        $this->assertSame(10, $tracker->inflight());
    }

    public function testReleaseDecrementsInflight(): void
    {
        $tracker = new ApcuBudgetTracker(budget: 10);

        $tracker->reserve(6);
        $tracker->release(4);

        $this->assertSame(2, $tracker->inflight());
    }

    public function testZeroOrNegativeReserveIsNoOp(): void
    {
        $tracker = new ApcuBudgetTracker(budget: 10);

        $this->assertTrue($tracker->reserve(0));
        $this->assertTrue($tracker->reserve(-5));
        $this->assertSame(0, $tracker->inflight());
    }
}
