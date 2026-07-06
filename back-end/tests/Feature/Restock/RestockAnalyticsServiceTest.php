<?php

namespace Tests\Feature\Restock;

use App\Services\Analytics\RestockAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestockAnalyticsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private RestockAnalyticsService $service;
    private Carbon $asOf;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RestockAnalyticsService::class);
        $this->asOf    = Carbon::parse('2026-07-06')->startOfDay();
        $this->warehouseId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeProduct(array $overrides = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Test Product',
            'product_type'       => 'consumable',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 0,
            'safety_stock'       => null,
            'max_stock_level'    => null,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides), 'product_id');
    }

    private function setStock(int $productId, int $qty): void
    {
        DB::table('inventory')->insert([
            'location_id'      => $this->warehouseId,
            'product_id'       => $productId,
            'quantity_on_hand' => $qty,
            'available_quantity' => $qty,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** One issuance of $qty pieces per day for $days days ending at asOf. */
    private function issueDaily(int $productId, int $qty, int $days): void
    {
        for ($i = 0; $i < $days; $i++) {
            $date = $this->asOf->copy()->subDays($i)->toDateString();
            $issuanceId = DB::table('issuances')->insertGetId([
                'location_id'   => $this->warehouseId,
                'issuance_date' => $date,
                'issuance_type' => 'Operations',
                'created_at'    => now(),
                'updated_at'    => now(),
            ], 'issuance_id');
            DB::table('issuance_details')->insert([
                'issuance_id'        => $issuanceId,
                'product_id'         => $productId,
                'quantity_issued'    => $qty,
                'quantity_in_pieces' => $qty,
                'unit_price'         => 100,
                'subtotal'           => 100 * $qty,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    private function findRec(array $result, int $productId): ?array
    {
        foreach ($result['recommendations'] as $rec) {
            if ((int) $rec['product_id'] === $productId) return $rec;
        }
        return null;
    }

    private function findSug(array $result, int $productId): ?array
    {
        foreach ($result['suggestions'] as $sug) {
            if ((int) $sug['product_id'] === $productId) return $sug;
        }
        return null;
    }

    // ---------------------------------------------------------------
    // (A) Fast mover: velocity, trend, safety stock, priority, reasons
    // ---------------------------------------------------------------
    public function test_fast_mover_gets_critical_recommendation_and_upward_suggestion(): void
    {
        // 2 pieces/day for the last 30 days only: v30=2.0, v90=0.6667, trend=3.0
        // sigma = 0.9428 -> safety = ceil(1.65*0.9428*sqrt(7)) = 5
        // reorder = ceil(2*7)+5 = 19 ; target = ceil(30*2)+5 = 65
        $pid = $this->makeProduct(['recommended_stocks' => 5, 'safety_stock' => 2, 'max_stock_level' => 10]);
        $this->setStock($pid, 4);
        $this->issueDaily($pid, 2, 30);

        $result = $this->service->analyze($this->asOf);

        $rec = $this->findRec($result, $pid);
        $this->assertNotNull($rec, 'fast mover must be recommended');
        $this->assertSame('CRITICAL', $rec['priority']);          // cover 2.0 < lead/2 = 3.5
        $this->assertSame(61, $rec['recommended_quantity']);      // 65 - 4 - 0
        $this->assertSame(19, $rec['reorder_level']);
        $this->assertSame(4, $rec['current_stock']);
        $this->assertEqualsWithDelta(2.0, (float) $rec['avg_daily_demand'], 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $rec['demand_trend'], 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $rec['days_of_cover'], 0.1);
        $this->assertEqualsWithDelta(7.0, (float) $rec['lead_time_days'], 0.001); // no PO history -> default
        foreach (['BELOW_SAFETY_STOCK', 'STOCKOUT_RISK', 'LOW_STOCK', 'FAST_MOVING'] as $code) {
            $this->assertContains($code, $rec['reason_codes']);
        }
        $this->assertNotContains('OUT_OF_STOCK', $rec['reason_codes']);

        $sug = $this->findSug($result, $pid);
        $this->assertNotNull($sug, 'drifted parameters must raise a suggestion');
        $this->assertSame(19, $sug['suggested_recommended']);
        $this->assertSame(5, $sug['suggested_safety']);
        $this->assertSame(65, $sug['suggested_max']);
        $this->assertSame(5, $sug['current_recommended']);
        $this->assertContains('FAST_MOVING', $sug['reason_codes']);
    }

    // ---------------------------------------------------------------
    // (B) Lead time measured from PO -> first receiving
    // ---------------------------------------------------------------
    public function test_lead_time_is_measured_from_po_history(): void
    {
        // Completed PO ordered 10 days ago, first receiving 4 days ago -> lead 6
        // Demand 1/day for 30 days: v30=1, sigma=0.4714 -> safety=ceil(1.65*0.4714*sqrt(6))=2
        // reorder = ceil(1*6)+2 = 8
        $pid = $this->makeProduct();
        $this->setStock($pid, 3);
        $this->issueDaily($pid, 1, 30);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'  => 'PO-TST-' . uniqid(),
            'order_date' => $this->asOf->copy()->subDays(10)->toDateString(),
            'status_id'  => 11, // Completed -> NOT open, so it must not count as on-order
            'created_at' => now(),
            'updated_at' => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poId, 'product_id' => $pid, 'quantity_ordered' => 5,
            'unit_price' => 80, 'subtotal' => 400, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $receivingId = DB::table('receivings')->insertGetId([
            'po_id' => $poId, 'location_id' => $this->warehouseId,
            'receiving_number' => 'RCV-TST-' . uniqid(),
            'receiving_date'   => $this->asOf->copy()->subDays(4)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], 'receiving_id');
        DB::table('receiving_details')->insert([
            'receiving_id' => $receivingId, 'product_id' => $pid,
            'quantity_amount' => 5, 'prod_price' => 80,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pid);

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(6.0, (float) $rec['lead_time_days'], 0.001);
        $this->assertSame(8, $rec['reorder_level']);
        $this->assertSame(0, $rec['on_order_quantity']);
        $this->assertContains('HIGH_TURNOVER', $rec['reason_codes']);
    }

    public function test_supplier_average_lead_time_is_used_when_product_has_no_receiving_history(): void
    {
        // Supplier S: product A's completed PO establishes supplier avg lead = 6 days.
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'TST Supplier ' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'supplier_id');

        $pidA = $this->makeProduct();
        $poA = DB::table('purchase_orders')->insertGetId([
            'po_number'   => 'PO-TST-' . uniqid(),
            'supplier_id' => $supplierId,
            'order_date'  => $this->asOf->copy()->subDays(10)->toDateString(),
            'status_id'   => 11, // Completed
            'created_at'  => now(),
            'updated_at'  => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poA, 'product_id' => $pidA, 'quantity_ordered' => 5,
            'unit_price' => 80, 'subtotal' => 400, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('receivings')->insert([
            'po_id' => $poA, 'location_id' => $this->warehouseId,
            'receiving_number' => 'RCV-TST-' . uniqid(),
            'receiving_date'   => $this->asOf->copy()->subDays(4)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Product B: same supplier, only a pending (never-received) PO -> must
        // inherit the supplier's 6-day average, not the 7-day default.
        // Demand 1/day for 30 days: sigma=0.4714 -> safety=ceil(1.65*0.4714*sqrt(6))=2
        // reorder=6+2=8 ; target=30+2=32 ; qty=32-3-1(open)=28
        $pidB = $this->makeProduct();
        $this->setStock($pidB, 3);
        $this->issueDaily($pidB, 1, 30);
        $poB = DB::table('purchase_orders')->insertGetId([
            'po_number'   => 'PO-TST-' . uniqid(),
            'supplier_id' => $supplierId,
            'order_date'  => $this->asOf->copy()->subDays(2)->toDateString(),
            'status_id'   => 7, // Pending -> open, no receivings
            'created_at'  => now(),
            'updated_at'  => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poB, 'product_id' => $pidB, 'quantity_ordered' => 1,
            'unit_price' => 80, 'subtotal' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pidB);

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(6.0, (float) $rec['lead_time_days'], 0.001);
        $this->assertSame(1, $rec['on_order_quantity']);
        $this->assertSame(28, $rec['recommended_quantity']);
    }

    // ---------------------------------------------------------------
    // (C) Open PO quantity is subtracted from the recommendation
    // ---------------------------------------------------------------
    public function test_open_po_quantity_reduces_recommended_quantity(): void
    {
        // Same demand as (B) but no completed PO -> lead defaults to 7
        // safety = ceil(1.65*0.4714*sqrt(7)) = ceil(2.058) = 3
        // reorder = 7+3 = 10 ; target = 30+3 = 33
        // open PO of 10 -> qty = 33 - 3 - 10 = 20
        $pid = $this->makeProduct();
        $this->setStock($pid, 3);
        $this->issueDaily($pid, 1, 30);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'  => 'PO-TST-' . uniqid(),
            'order_date' => $this->asOf->copy()->subDays(2)->toDateString(),
            'status_id'  => 7, // Pending -> open
            'created_at' => now(),
            'updated_at' => now(),
        ], 'po_id');
        DB::table('purchase_order_details')->insert([
            'po_id' => $poId, 'product_id' => $pid, 'quantity_ordered' => 10,
            'unit_price' => 80, 'subtotal' => 800, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pid);

        $this->assertNotNull($rec);
        $this->assertSame(10, $rec['on_order_quantity']);
        $this->assertSame(20, $rec['recommended_quantity']);
    }

    // ---------------------------------------------------------------
    // (D) Sales count as demand too
    // ---------------------------------------------------------------
    public function test_sales_details_count_toward_demand(): void
    {
        $pid = $this->makeProduct();
        $this->setStock($pid, 0);
        // 3 units sold on each of the last 10 days: total30 = 30 -> v30 = 1.0
        for ($i = 0; $i < 10; $i++) {
            $salesId = DB::table('sales')->insertGetId([
                'invoice_number' => 'INV-TST-' . uniqid(),
                'sale_date'      => $this->asOf->copy()->subDays($i)->toDateString(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ], 'sales_id');
            DB::table('sales_details')->insert([
                'sales_id' => $salesId, 'product_id' => $pid, 'quantity' => 3,
                'unit_price' => 100, 'subtotal' => 300, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $result = $this->service->analyze($this->asOf);
        $rec = $this->findRec($result, $pid);

        $this->assertNotNull($rec);
        $this->assertEqualsWithDelta(1.0, (float) $rec['avg_daily_demand'], 0.001);
        $this->assertContains('OUT_OF_STOCK', $rec['reason_codes']);
        $this->assertSame('CRITICAL', $rec['priority']);
    }

    // ---------------------------------------------------------------
    // (E) No movement: halving suggestion; no-history+no-params skipped
    // ---------------------------------------------------------------
    public function test_no_movement_product_gets_halving_suggestion(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 20, 'safety_stock' => 10, 'max_stock_level' => 40]);
        $this->setStock($pid, 50); // plenty of stock, no demand

        $result = $this->service->analyze($this->asOf);

        $this->assertNull($this->findRec($result, $pid), 'well-stocked product must not be recommended');
        $sug = $this->findSug($result, $pid);
        $this->assertNotNull($sug);
        $this->assertSame(['NO_MOVEMENT'], $sug['reason_codes']);
        $this->assertSame(10, $sug['suggested_recommended']);
        $this->assertSame(5, $sug['suggested_safety']);
        $this->assertSame(20, $sug['suggested_max']);
    }

    public function test_product_with_no_history_and_no_params_is_skipped(): void
    {
        $pid = $this->makeProduct(); // params all 0/null, no stock row, no movement
        $result = $this->service->analyze($this->asOf);
        $this->assertNull($this->findRec($result, $pid));
        $this->assertNull($this->findSug($result, $pid));
    }

    public function test_inactive_product_is_skipped(): void
    {
        $pid = $this->makeProduct(['status_id' => 4, 'recommended_stocks' => 10]);
        $this->setStock($pid, 0);
        $result = $this->service->analyze($this->asOf);
        $this->assertNull($this->findRec($result, $pid));
        $this->assertNull($this->findSug($result, $pid));
    }

    // ---------------------------------------------------------------
    // (F) Persistence
    // ---------------------------------------------------------------
    public function test_run_persists_recommendations_and_replaces_previous_batch(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 5]);
        $this->setStock($pid, 1);
        $this->issueDaily($pid, 2, 30);

        $result = $this->service->run($this->asOf);

        $this->assertDatabaseHas('restock_recommendations', ['product_id' => $pid]);
        $first = DB::table('restock_recommendations')->where('product_id', $pid)->first();
        $this->assertNotNull($first->run_at);
        $this->assertSame($result['warehouse_location_id'], (int) $first->location_id);
        $this->assertNotNull($result['warehouse_location_id']);

        // Second run replaces the batch (no duplicate rows)
        $this->service->run($this->asOf);
        $this->assertSame(
            1,
            DB::table('restock_recommendations')->where('product_id', $pid)->count()
        );
    }

    public function test_pending_suggestion_is_updated_not_duplicated(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 5, 'safety_stock' => 2, 'max_stock_level' => 10]);
        $this->setStock($pid, 4);
        $this->issueDaily($pid, 2, 30);

        $this->service->run($this->asOf);
        $this->service->run($this->asOf);

        $this->assertSame(
            1,
            DB::table('stock_parameter_suggestions')
                ->where('product_id', $pid)->where('status', 'pending')->count()
        );
    }

    public function test_dismissed_suggestion_with_same_values_is_not_re_raised(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 5, 'safety_stock' => 2, 'max_stock_level' => 10]);
        $this->setStock($pid, 4);
        $this->issueDaily($pid, 2, 30);

        $this->service->run($this->asOf);
        DB::table('stock_parameter_suggestions')
            ->where('product_id', $pid)->where('status', 'pending')
            ->update(['status' => 'dismissed', 'reviewed_at' => now()]);

        $this->service->run($this->asOf);

        $this->assertSame(
            0,
            DB::table('stock_parameter_suggestions')
                ->where('product_id', $pid)->where('status', 'pending')->count()
        );
    }

    public function test_stale_pending_suggestions_are_removed_when_no_longer_warranted(): void
    {
        $pid = $this->makeProduct(['recommended_stocks' => 20]);
        DB::table('stock_parameter_suggestions')->insert([
            'product_id'            => $pid,
            'current_recommended'   => 20,
            'suggested_recommended' => 40,
            'status'                => 'pending',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
        $this->setStock($pid, 100);
        $this->issueDaily($pid, 1, 90); // steady demand -> computed values close to current? No:
        // steady 1/day: reorder = ceil(7)+SS. Whatever the suggestion outcome, the OLD pending
        // row (suggested 40) must be replaced by the engine's own values or deleted -- never
        // left stale. We assert on count + freshness, not on exact values.

        $this->service->run($this->asOf);

        $pending = DB::table('stock_parameter_suggestions')
            ->where('product_id', $pid)->where('status', 'pending')->get();
        $this->assertLessThanOrEqual(1, $pending->count());
        if ($pending->count() === 1) {
            $this->assertNotSame(40, (int) $pending->first()->suggested_recommended);
        }
    }
}
