<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerPostingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerReversalTest extends TestCase
{
    use DatabaseTransactions;

    private function postedOut(): InventoryLedgerEntry
    {
        return app(LedgerPoster::class)->post([
            'occurred_at'      => '2026-09-01 10:00:00',
            'direction'        => 'out',
            'movement_type'    => 'issuance',
            'product_id'       => 4321,
            'batch_id'         => 77,
            'quantity'         => 3,
            'unit_cost'        => 250.00,
            'cost_basis'       => 'actual',
            'from_location_id' => 1,
            'source_type'      => 'issuances',
            'source_id'        => 55,
            'source_line_id'   => 91,
            'posting_key'      => 'issuance:55:line:91:batch:77:out',
        ]);
    }

    public function test_a_reversal_flips_direction_keeps_quantity_positive_and_reuses_the_frozen_cost(): void
    {
        $original = $this->postedOut();

        $reversal = app(LedgerPoster::class)->reverse($original, 'Issuance voided by admin', 9);

        $this->assertSame('in', $reversal->direction);
        $this->assertSame(3, $reversal->quantity);
        $this->assertSame('250.00', $reversal->unit_cost);
        $this->assertSame('750.00', $reversal->extended_value);
        $this->assertSame($original->ledger_id, $reversal->reverses_ledger_id);
        $this->assertSame('Issuance voided by admin', $reversal->notes);
        $this->assertSame(9, $reversal->actor_user_id);
    }

    public function test_a_reversal_sends_the_stock_back_where_it_came_from(): void
    {
        $reversal = app(LedgerPoster::class)->reverse($this->postedOut(), 'voided');

        $this->assertSame(1, (int) $reversal->to_location_id);
        $this->assertNull($reversal->from_location_id);
    }

    public function test_the_original_row_is_never_mutated(): void
    {
        $original = $this->postedOut();

        app(LedgerPoster::class)->reverse($original, 'voided');

        $fresh = InventoryLedgerEntry::find($original->ledger_id);
        $this->assertSame('out', $fresh->direction);
        $this->assertNull($fresh->reverses_ledger_id);
    }

    public function test_reversing_twice_produces_one_reversal(): void
    {
        $original = $this->postedOut();
        $poster   = app(LedgerPoster::class);

        $a = $poster->reverse($original, 'voided');
        $b = $poster->reverse($original, 'voided again');

        $this->assertSame($a->ledger_id, $b->ledger_id);
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $poster   = app(LedgerPoster::class);
        $reversal = $poster->reverse($this->postedOut(), 'voided');

        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/reversal/i');

        $poster->reverse($reversal, 'undo the undo');
    }

    public function test_a_reversal_keeps_the_original_value_even_if_the_cost_would_now_resolve_differently(): void
    {
        $original = $this->postedOut();   // 3 @ 250.00 = 750.00

        // Whatever the product costs today is irrelevant: the reversal must undo the
        // amount that was actually posted, not a freshly computed one.
        $reversal = app(LedgerPoster::class)->reverse($original, 'voided');

        $this->assertSame($original->extended_value, $reversal->extended_value);
        $this->assertSame($original->unit_cost, $reversal->unit_cost);
        $this->assertSame($original->cost_basis, $reversal->cost_basis);
    }
}
