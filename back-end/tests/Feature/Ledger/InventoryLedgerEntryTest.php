<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryLedgerEntryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_casts_money_quantities_and_timestamps(): void
    {
        $entry = InventoryLedgerEntry::create([
            'occurred_at'    => '2026-09-01 08:30:00',
            'posted_at'      => '2026-09-01 08:30:05',
            'direction'      => 'in',
            'movement_type'  => 'receiving',
            'lens'           => 'inventory',
            'quantity'       => '12',
            'unit_cost'      => '1500.50',
            'extended_value' => '18006.00',
            'cost_basis'     => 'actual',
            'source_type'    => 'receivings',
            'source_id'      => 7,
            'correlation_id' => 'receivings:7',
            'posting_key'    => 'test:cast:1',
        ]);

        $fresh = InventoryLedgerEntry::find($entry->ledger_id);

        $this->assertSame(12, $fresh->quantity);
        $this->assertSame('1500.50', $fresh->unit_cost);
        $this->assertSame('2026-09-01', $fresh->occurred_at->format('Y-m-d'));
        $this->assertFalse($fresh->isReversal());
    }

    public function test_a_row_with_reverses_ledger_id_reports_itself_as_a_reversal(): void
    {
        $entry = new InventoryLedgerEntry(['reverses_ledger_id' => 99]);

        $this->assertTrue($entry->isReversal());
    }

    public function test_two_rows_cannot_share_a_posting_key(): void
    {
        // The unique index is the only thing standing between a retried request and
        // double-counted value, so prove it enforces rather than merely existing.
        $attrs = [
            'occurred_at'    => '2026-09-01 08:30:00',
            'posted_at'      => '2026-09-01 08:30:05',
            'direction'      => 'in',
            'movement_type'  => 'receiving',
            'lens'           => 'inventory',
            'quantity'       => 12,
            'unit_cost'      => '1500.50',
            'extended_value' => '18006.00',
            'cost_basis'     => 'actual',
            'source_type'    => 'receivings',
            'source_id'      => 7,
            'correlation_id' => 'receivings:7',
        ];

        InventoryLedgerEntry::create($attrs + ['posting_key' => 'test:dupe:1']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        InventoryLedgerEntry::create($attrs + ['posting_key' => 'test:dupe:1']);
    }
}
