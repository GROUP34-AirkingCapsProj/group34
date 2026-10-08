<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerPostingException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerPosterTest extends TestCase
{
    use DatabaseTransactions;

    private function poster(): LedgerPoster
    {
        return app(LedgerPoster::class);
    }

    /** @return array<string,mixed> */
    private function validOut(array $overrides = []): array
    {
        return array_merge([
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
            'correlation_id'   => 'issuances:55',
            'actor_user_id'    => null,
            'posting_key'      => 'issuance:55:line:91:batch:77:out',
        ], $overrides);
    }

    public function test_it_computes_extended_value_from_quantity_and_unit_cost(): void
    {
        $entry = $this->poster()->post($this->validOut());

        $this->assertSame('750.00', $entry->extended_value);
        $this->assertSame('posted', $entry->origin);
        $this->assertSame('inventory', $entry->lens);
        $this->assertNotNull($entry->posted_at);
    }

    public function test_posting_the_same_key_twice_returns_the_first_row_instead_of_doubling_value(): void
    {
        $first  = $this->poster()->post($this->validOut());
        $second = $this->poster()->post($this->validOut());

        $this->assertSame($first->ledger_id, $second->ledger_id);
        $this->assertSame(1, InventoryLedgerEntry::where('posting_key', $first->posting_key)->count());
    }

    public function test_an_unavailable_cost_records_the_quantity_and_a_null_value(): void
    {
        $entry = $this->poster()->post($this->validOut([
            'unit_cost'   => null,
            'cost_basis'  => 'unavailable',
            'posting_key' => 'issuance:55:line:92:batch:77:out',
        ]));

        $this->assertSame(3, $entry->quantity);
        $this->assertNull($entry->extended_value);
    }

    public function test_it_refuses_a_stock_movement_with_no_product(): void
    {
        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/product_id/');

        $this->poster()->post($this->validOut(['product_id' => null]));
    }

    public function test_it_refuses_an_out_movement_with_no_source_location(): void
    {
        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/from_location_id/');

        $this->poster()->post($this->validOut(['from_location_id' => null]));
    }

    public function test_it_refuses_a_zero_quantity_stock_movement(): void
    {
        $this->expectException(LedgerPostingException::class);
        $this->expectExceptionMessageMatches('/quantity/');

        $this->poster()->post($this->validOut(['quantity' => 0]));
    }

    public function test_it_refuses_an_unknown_direction_or_cost_basis(): void
    {
        $this->expectException(LedgerPostingException::class);

        $this->poster()->post($this->validOut(['direction' => 'sideways']));
    }

    public function test_a_finance_row_needs_no_product_or_quantity(): void
    {
        $entry = $this->poster()->post([
            'occurred_at'    => '2026-09-01 10:00:00',
            'direction'      => 'neutral',
            'movement_type'  => 'budget_allocation',
            'lens'           => 'finance',
            'extended_value' => 50000.00,
            'cost_basis'     => 'actual',
            'source_type'    => 'budget_allocations',
            'source_id'      => 3,
            'correlation_id' => 'budget_allocations:3',
            'posting_key'    => 'budget:3:verified',
        ]);

        $this->assertSame(0, $entry->quantity);
        $this->assertSame('50000.00', $entry->extended_value);
    }
}
