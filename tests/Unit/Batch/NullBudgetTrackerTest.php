<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Unit\Batch;

use JsonRpcServer\Batch\NullBudgetTracker;
use PHPUnit\Framework\TestCase;

final class NullBudgetTrackerTest extends TestCase
{
    public function testAlwaysAllowsReservationsAndReportsZeroInflight(): void
    {
        $tracker = new NullBudgetTracker();

        $this->assertTrue($tracker->reserve(1));
        $this->assertTrue($tracker->reserve(1_000));
        $this->assertSame(0, $tracker->inflight());

        // No-op release — does not error.
        $tracker->release(5);
        $this->assertSame(0, $tracker->inflight());
    }
}
