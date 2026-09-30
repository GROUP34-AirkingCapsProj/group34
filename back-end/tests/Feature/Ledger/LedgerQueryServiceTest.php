<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Services\Ledger\LedgerFilter;
use App\Services\Ledger\LedgerQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LedgerQueryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $productId = 880111;
    private int $locationId = 990111;

    private function entry(array $overrides = []): InventoryLedgerEntry
    {
        return InventoryLedgerEntry::create(array_merge([
            'occurred_at'    => '2026-09-10 09:00:00',
            'posted_at'      => '2026-09-10 09:00:00',
            'direction'      => 'in',
            'movement_type'  => 'receiving',
            'lens'           => 'inventory',
            'product_id'     => $this->productId,
            'quantity'       => 10,
            'unit_cost'      => 100.00,
            'extended_value' => 1000.00,
            'cost_basis'     => 'actual',
            'to_location_id' => $this->locationId,
            'source_type'    => 'receivings',
            'source_id'      => 1,
            'correlation_id' => 'receivings:1',
            'origin'         => 'posted',
            'posting_key'    => 'q:' . uniqid('', true),
        ], $overrides));
    }

    private function filter(array $q = []): LedgerFilter
    {
        return LedgerFilter::fromArray(array_merge([
            'from' => '2026-09-01',
            'to'   => '2026-09-30',
        ], $q));
    }

    private function svc(): LedgerQueryService
    {
        return app(LedgerQueryService::class);
    }

    public function test_the_summary_totals_quantity_and_value_by_direction(): void
    {
        $this->entry();
        $this->entry([
            'direction' => 'out', 'movement_type' => 'issuance', 'quantity' => 4,
            'unit_cost' => 100.00, 'extended_value' => 400.00,
            'to_location_id' => null, 'from_location_id' => $this->locationId,
            'source_type' => 'issuances', 'source_id' => 2, 'correlation_id' => 'issuances:2',
        ]);

        $s = $this->svc()->summary($this->filter());

        $this->assertSame(10, $s['quantity_in']);
        $this->assertSame(4, $s['quantity_out']);
        $this->assertSame(1000.0, $s['value_in']);
        $this->assertSame(400.0, $s['value_out']);
        $this->assertSame(6, $s['net_quantity']);
        $this->assertSame(2, $s['event_count']);
    }

    public function test_the_summary_reports_how_much_value_rests_on_each_cost_basis(): void
    {
        $this->entry();
        $this->entry([
            'cost_basis' => 'derived_weighted_avg', 'extended_value' => 500.00,
            'source_id' => 3, 'correlation_id' => 'receivings:3',
        ]);
        $this->entry([
            'cost_basis' => 'unavailable', 'unit_cost' => null, 'extended_value' => null,
            'source_id' => 4, 'correlation_id' => 'receivings:4',
        ]);

        $s = $this->svc()->summary($this->filter());

        $this->assertSame(1000.0, $s['cost_basis']['actual']);
        $this->assertSame(500.0, $s['cost_basis']['derived_weighted_avg']);
        $this->assertSame(1, $s['unvalued_rows']);
    }

    public function test_a_reversed_pair_nets_out_of_the_summary(): void
    {
        $original = $this->entry([
            'direction' => 'out', 'movement_type' => 'issuance', 'quantity' => 5,
            'extended_value' => 500.00, 'from_location_id' => $this->locationId, 'to_location_id' => null,
            'source_type' => 'issuances', 'source_id' => 9, 'correlation_id' => 'issuances:9',
        ]);
        $this->entry([
            'direction' => 'in', 'movement_type' => 'issuance', 'quantity' => 5,
            'extended_value' => 500.00, 'to_location_id' => $this->locationId, 'from_location_id' => null,
            'source_type' => 'issuances', 'source_id' => 9, 'correlation_id' => 'issuances:9',
            'reverses_ledger_id' => $original->ledger_id,
        ]);

        $s = $this->svc()->summary($this->filter());

        $this->assertSame(0, $s['net_quantity']);
        $this->assertSame(1, $s['reversal_count']);
    }

    public function test_the_date_window_excludes_movements_outside_it(): void
    {
        $this->entry(['occurred_at' => '2026-08-15 09:00:00']);

        $this->assertSame(0, $this->svc()->summary($this->filter())['event_count']);
    }

    public function test_movements_are_paginated_newest_first(): void
    {
        $this->entry(['occurred_at' => '2026-09-05 09:00:00', 'source_id' => 11, 'correlation_id' => 'receivings:11']);
        $this->entry(['occurred_at' => '2026-09-20 09:00:00', 'source_id' => 12, 'correlation_id' => 'receivings:12']);

        $page = $this->svc()->movements($this->filter(), 1);

        $this->assertSame(2, $page->total());
        $this->assertSame('receivings:12', $page->items()[0]['correlation_id']);
    }

    public function test_a_movement_row_flags_a_backdated_posting(): void
    {
        $this->entry([
            'occurred_at' => '2026-09-01 09:00:00',
            'posted_at'   => '2026-09-25 09:00:00',
        ]);

        $this->assertTrue($this->svc()->movements($this->filter(), 10)->items()[0]['is_backdated']);
    }

    public function test_a_period_wider_than_the_cap_is_refused_rather_than_silently_truncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/366 days/');

        LedgerFilter::fromArray(['from' => '2024-01-01', 'to' => '2026-09-30']);
    }

    // ------------------------------------------------------------- breakdown

    public function test_a_breakdown_by_movement_type_groups_and_totals(): void
    {
        $this->entry(['occurred_at' => '2026-09-02 09:00:00']);
        $this->entry([
            'occurred_at' => '2026-09-03 09:00:00', 'direction' => 'out', 'movement_type' => 'issuance',
            'quantity' => 4, 'extended_value' => 400.00,
            'from_location_id' => $this->locationId, 'to_location_id' => null,
            'source_type' => 'issuances', 'source_id' => 21, 'correlation_id' => 'issuances:21',
        ]);
        $this->entry([
            'occurred_at' => '2026-09-04 09:00:00', 'direction' => 'out', 'movement_type' => 'issuance',
            'quantity' => 2, 'extended_value' => 200.00,
            'from_location_id' => $this->locationId, 'to_location_id' => null,
            'source_type' => 'issuances', 'source_id' => 22, 'correlation_id' => 'issuances:22',
        ]);

        $rows = $this->svc()->breakdown($this->filter(), 'movement_type');
        $issuance = collect($rows)->firstWhere('key', 'issuance');

        $this->assertSame(6, $issuance['quantity_out']);
        $this->assertSame(600.0, $issuance['value_out']);
        $this->assertSame(2, $issuance['row_count']);
    }

    public function test_an_unknown_breakdown_dimension_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->svc()->breakdown($this->filter(), 'favourite_colour');
    }

    // -------------------------------------------------------- reconciliation

    public function test_opening_plus_in_minus_out_equals_closing(): void
    {
        $f = ['product_id' => $this->productId, 'location_id' => $this->locationId];

        $this->entry(['occurred_at' => '2026-08-01 09:00:00', 'quantity' => 100, 'extended_value' => 10000.00]);
        $this->entry([
            'occurred_at' => '2026-08-20 09:00:00', 'direction' => 'out', 'quantity' => 30,
            'extended_value' => 3000.00, 'from_location_id' => $this->locationId, 'to_location_id' => null,
        ]);
        $this->entry(['occurred_at' => '2026-09-10 09:00:00', 'quantity' => 50, 'extended_value' => 5000.00]);
        $this->entry([
            'occurred_at' => '2026-09-15 09:00:00', 'direction' => 'out', 'quantity' => 20,
            'extended_value' => 2000.00, 'from_location_id' => $this->locationId, 'to_location_id' => null,
        ]);

        $r = $this->svc()->reconciliation($this->filter($f));

        $this->assertSame(70, $r['opening_quantity']);
        $this->assertSame(50, $r['quantity_in']);
        $this->assertSame(20, $r['quantity_out']);
        $this->assertSame(100, $r['closing_quantity']);
    }

    public function test_value_reconciles_the_same_way_because_costs_are_frozen(): void
    {
        $f = ['product_id' => $this->productId, 'location_id' => $this->locationId];

        $this->entry(['occurred_at' => '2026-08-01 09:00:00', 'quantity' => 10, 'extended_value' => 1000.00]);
        $this->entry(['occurred_at' => '2026-09-10 09:00:00', 'quantity' => 10, 'extended_value' => 3000.00]);
        $this->entry([
            'occurred_at' => '2026-09-11 09:00:00', 'direction' => 'out', 'quantity' => 5,
            'extended_value' => 500.00, 'from_location_id' => $this->locationId, 'to_location_id' => null,
        ]);

        $r = $this->svc()->reconciliation($this->filter($f));

        $this->assertSame(1000.0, $r['opening_value']);
        $this->assertSame(3000.0, $r['value_in']);
        $this->assertSame(500.0, $r['value_out']);
        $this->assertSame(3500.0, $r['closing_value']);
        $this->assertTrue($r['value_provable']);
    }

    public function test_value_is_not_claimed_provable_when_a_row_had_no_cost(): void
    {
        $f = ['product_id' => $this->productId, 'location_id' => $this->locationId];

        $this->entry(['occurred_at' => '2026-09-10 09:00:00', 'cost_basis' => 'unavailable',
            'unit_cost' => null, 'extended_value' => null]);

        $r = $this->svc()->reconciliation($this->filter($f));

        $this->assertFalse($r['value_provable']);
        $this->assertSame(1, $r['unvalued_rows']);
    }

    public function test_a_wide_scope_reports_no_stock_comparison_rather_than_a_meaningless_one(): void
    {
        $this->entry();

        // No product/location pinned, so there is no single on-hand figure to
        // compare against; saying "balances" here would be a claim we cannot make.
        $r = $this->svc()->reconciliation($this->filter());

        $this->assertNull($r['stock_on_hand']);
        $this->assertNull($r['matches_stock']);
    }
}
