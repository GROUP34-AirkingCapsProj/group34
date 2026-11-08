<?php

namespace Tests\Unit;

use App\Support\PoLifecycle;
use PHPUnit\Framework\TestCase;

class PoLifecycleTest extends TestCase
{
    public function test_workflow_states_and_line_balances(): void
    {
        $line = ['ordered' => 10, 'received' => 0, 'short_closed' => 0];
        $this->assertSame(PoLifecycle::DRAFT, PoLifecycle::compute([$line], false));
        $this->assertSame(PoLifecycle::OPEN, PoLifecycle::compute([$line], true));
        $this->assertSame(PoLifecycle::CANCELLED, PoLifecycle::compute([$line], true, true));
        $line['received'] = 4;
        $this->assertSame(PoLifecycle::PARTIALLY_RECEIVED, PoLifecycle::compute([$line], true, true));
        $line['short_closed'] = 6;
        $this->assertSame(PoLifecycle::SHORT_CLOSED, PoLifecycle::compute([$line], true));
        $line['received'] = 10;
        $line['short_closed'] = 0;
        $this->assertSame(PoLifecycle::FULLY_RECEIVED, PoLifecycle::compute([$line], true));
        $this->assertSame(PoLifecycle::OPEN, PoLifecycle::compute([], true));
    }

    public function test_overfilled_line_cannot_compensate_for_an_unreceived_line(): void
    {
        $this->assertSame(PoLifecycle::PARTIALLY_RECEIVED, PoLifecycle::compute([
            ['ordered' => 10, 'received' => 20, 'short_closed' => 0],
            ['ordered' => 10, 'received' => 0, 'short_closed' => 0],
        ], true));
    }

    public function test_all_closed_without_receipts_is_short_closed(): void
    {
        $this->assertSame(PoLifecycle::SHORT_CLOSED, PoLifecycle::compute([
            ['ordered' => 10, 'received' => 0, 'short_closed' => 10],
        ], true));
    }
}
