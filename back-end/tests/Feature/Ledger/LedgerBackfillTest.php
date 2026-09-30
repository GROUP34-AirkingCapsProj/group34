<?php

namespace Tests\Feature\Ledger;

use App\Models\InventoryLedgerEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * History reconstructed from the source tables, marked so an auditor can tell it
 * apart from evidence we recorded live. Keys match the live paths exactly, so a
 * backfill can never duplicate or overwrite a real posting.
 */
class LedgerBackfillTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Receiving, 1: ReceivingDetail, 2: Product, 3: Location} */
    private function historicReceiving(float $price, int $qty = 8): array
    {
        $location = Location::create([
            'location_code' => 'L-' . uniqid(), 'location_name' => 'WH', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'M-' . uniqid(), 'product_name' => 'Old Stock',
            'product_type' => 'installation_material', 'cost_price' => 5.00,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => '2026-03-15',
            'total_quantity_received' => $qty,
            'total_quantity_damaged' => 0,
            'location_id' => $location->location_id,
        ]);
        $detail = ReceivingDetail::create([
            'receiving_id' => $receiving->receiving_id,
            'product_id' => $product->product_id,
            'quantity_amount' => $qty,
            'prod_price' => $price,
            'condition' => 'Good',
            'entry_mode' => 'counted',
        ]);

        return [$receiving, $detail, $product, $location];
    }

    private function runBackfill(array $opts = []): void
    {
        $this->artisan('ledger:backfill', array_merge([
            '--source' => 'receivings', '--from' => '2026-01-01', '--to' => '2026-12-31',
        ], $opts))->assertExitCode(0);
    }

    private function rowsFor(Receiving $receiving)
    {
        return InventoryLedgerEntry::where('source_type', 'receivings')
            ->where('source_id', $receiving->receiving_id)
            ->get();
    }

    public function test_it_reconstructs_a_historic_receiving_and_marks_it_reconstructed(): void
    {
        [$receiving, $detail, $product, $location] = $this->historicReceiving(42.50);

        $this->runBackfill();

        $row = $this->rowsFor($receiving)->firstOrFail();

        $this->assertSame('reconstructed', $row->origin);
        $this->assertSame('in', $row->direction);
        $this->assertSame(8, (int) $row->quantity);
        $this->assertSame('42.50', $row->unit_cost);
        $this->assertSame('2026-03-15', $row->occurred_at->format('Y-m-d'));
        $this->assertSame((int) $location->location_id, (int) $row->to_location_id);
        $this->assertSame((int) $detail->receiving_detail_id, (int) $row->source_line_id);
    }

    public function test_a_line_with_no_recoverable_price_records_quantity_and_no_value(): void
    {
        [$receiving] = $this->historicReceiving(0.0);

        $this->runBackfill();

        $row = $this->rowsFor($receiving)->firstOrFail();

        $this->assertSame('unavailable', $row->cost_basis);
        $this->assertNull($row->extended_value);
        $this->assertSame(8, (int) $row->quantity, 'The quantity is still evidence.');
    }

    public function test_running_it_twice_writes_the_same_rows(): void
    {
        [$receiving] = $this->historicReceiving(10.00);

        $this->runBackfill();
        $after = $this->rowsFor($receiving)->count();

        $this->runBackfill();

        $this->assertSame($after, $this->rowsFor($receiving)->count());
        $this->assertSame(1, $after);
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$receiving] = $this->historicReceiving(10.00);

        $this->runBackfill(['--dry-run' => true]);

        $this->assertCount(0, $this->rowsFor($receiving));
    }

    public function test_it_will_not_overwrite_a_live_posted_row(): void
    {
        [$receiving, $detail, $product, $location] = $this->historicReceiving(10.00);

        // A row already posted live, carrying the same deterministic key.
        InventoryLedgerEntry::create([
            'occurred_at' => '2026-03-15 00:00:00', 'posted_at' => '2026-03-15 00:00:00',
            'direction' => 'in', 'movement_type' => 'receiving', 'lens' => 'inventory',
            'product_id' => $product->product_id, 'quantity' => 8,
            'unit_cost' => 10.00, 'extended_value' => 80.00, 'cost_basis' => 'actual',
            'to_location_id' => $location->location_id,
            'source_type' => 'receivings', 'source_id' => $receiving->receiving_id,
            'source_line_id' => $detail->receiving_detail_id,
            'correlation_id' => 'receivings:' . $receiving->receiving_id, 'origin' => 'posted',
            'posting_key' => 'receiving:' . $receiving->receiving_id
                             . ':line:' . $detail->receiving_detail_id . ':in',
        ]);

        $this->runBackfill();

        $rows = $this->rowsFor($receiving);
        $this->assertCount(1, $rows);
        $this->assertSame('posted', $rows->first()->origin, 'Live evidence must not be downgraded to reconstruction.');
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $this->artisan('ledger:backfill', ['--source' => 'nonsense'])->assertExitCode(1);
    }
}
