<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Tests\Unit\Batch;

use Knetesin\JsonRpcServerBundle\Batch\ApcuBudgetTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ApcuBudgetTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        (new \ReflectionProperty(ApcuBudgetTracker::class, 'unavailableWarned'))->setValue(null, false);
    }

    public function testWithoutApcuReserveFailsAndWarnsOncePerProcess(): void
    {
        $this->requireApcuUnusable();
        $logger = new RecordingLogger();

        $tracker = new ApcuBudgetTracker(budget: 10, logger: $logger);
        $this->assertFalse($tracker->reserve(3));
        $this->assertFalse($tracker->reserve(1));
        $this->assertFalse((new ApcuBudgetTracker(budget: 10, logger: $logger))->reserve(1));

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]);
    }

    public function testWithoutApcuReleaseAndInflightAreNoOps(): void
    {
        $this->requireApcuUnusable();
        $tracker = new ApcuBudgetTracker(budget: 10);

        $tracker->release(3);

        $this->assertSame(0, $tracker->inflight());
        // Zero-sized reservations never touch APCu.
        $this->assertTrue($tracker->reserve(0));
    }

    public function testReserveSucceedsBelowBudget(): void
    {
        $this->requireApcu();
        $tracker = new ApcuBudgetTracker(budget: 10);

        $this->assertTrue($tracker->reserve(3));
        $this->assertSame(3, $tracker->inflight());

        $this->assertTrue($tracker->reserve(7));
        $this->assertSame(10, $tracker->inflight());
    }

    public function testReserveFailsWhenItWouldExceedBudgetAndRollsBack(): void
    {
        $this->requireApcu();
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
        $this->requireApcu();
        $tracker = new ApcuBudgetTracker(budget: 10);

        $tracker->reserve(6);
        $tracker->release(4);

        $this->assertSame(2, $tracker->inflight());
    }

    public function testZeroOrNegativeReserveIsNoOp(): void
    {
        $this->requireApcu();
        $tracker = new ApcuBudgetTracker(budget: 10);

        $this->assertTrue($tracker->reserve(0));
        $this->assertTrue($tracker->reserve(-5));
        $this->assertSame(0, $tracker->inflight());
    }

    private function requireApcu(): void
    {
        if (!ApcuBudgetTracker::isAvailable()) {
            self::markTestSkipped(
                'APCu not usable in this PHP runtime (missing extension, apc.enabled=0, apc.enable_cli=0 on CLI, or shared memory unavailable).',
            );
        }
        // Fresh counter for every test.
        apcu_clear_cache();
    }

    private function requireApcuUnusable(): void
    {
        if (\function_exists('apcu_enabled') && apcu_enabled()) {
            self::markTestSkipped('APCu is usable in this PHP runtime; the missing-APCu path is not reachable.');
        }
    }
}

/** @internal */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = (string) $level;
    }
}
