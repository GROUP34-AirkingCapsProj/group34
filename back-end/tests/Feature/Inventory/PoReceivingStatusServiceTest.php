<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
use App\Services\PoLifecycleBackfillService;
use App\Services\PoLineBalanceService;
use App\Services\PoReceivingStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PoReceivingStatusServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makePo(int $ppp, int $orderedPackages, int $receivedPieces): PurchaseOrder
    {
        $location = Location::create([
            'location_code' => 'LOC-'.uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-'.uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp, 'is_serialized' => 0,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 5, 'subtotal' => 50,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-'.uniqid(), 'receiving_date' => now()->toDateString(),
            'location_id' => $location->location_id, 'po_id' => $po->po_id,
            'total_quantity_received' => $receivedPieces, 'total_quantity_damaged' => 0,
        ]);
        DB::table('receiving_details')->insert([
            'receiving_id' => $receiving->receiving_id, 'po_detail_id' => $poDetail->po_detail_id,
            'product_id' => $product->product_id, 'quantity_amount' => $receivedPieces,
            'prod_price' => 5, 'condition' => 'Good', 'entry_mode' => 'counted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $po;
    }

    public function test_partial_receipt_sets_partially_received(): void
    {
        // 10 packages x 50 ppp = 500 pieces ordered; only 100 received.
        $po = $this->makePo(50, 10, 100);

        app(PoReceivingStatusService::class)->sync((int) $po->po_id);

        $this->assertSame(12, (int) $po->fresh()->status_id);
    }

    public function test_exact_receipt_sets_completed(): void
    {
        // 2 packages x 50 ppp = 100 pieces ordered; exactly 100 received.
        $po = $this->makePo(50, 2, 100);

        app(PoReceivingStatusService::class)->sync((int) $po->po_id);

        $this->assertSame(11, (int) $po->fresh()->status_id);
    }

    public function test_over_receipt_still_reaches_completed(): void
    {
        // 2 packages x 50 ppp = 100 pieces ordered; 130 received (over-receipt).
        $po = $this->makePo(50, 2, 130);

        app(PoReceivingStatusService::class)->sync((int) $po->po_id);

        $this->assertSame(
            11,
            (int) $po->fresh()->status_id,
            'The >= comparison in PoReceivingStatusService must leave an over-received PO '
                .'Completed, not stuck open — do not "fix" it to =='
        );
    }

    public function test_package_snapshot_survives_product_changes(): void
    {
        $po = $this->makePo(50, 2, 50);
        $line = $po->details()->first();
        InstallationMaterialDetail::where('product_id', $line->product_id)->update(['pieces_per_package' => 5]);
        app(PoReceivingStatusService::class)->sync((int) $po->po_id);
        $this->assertSame(12, (int) $po->fresh()->status_id);
        $balance = app(PoLineBalanceService::class)->forPo($po)[$line->po_detail_id];
        $this->assertSame(100, $balance['ordered']);
        $this->assertSame(50, $balance['remaining']);
    }

    public function test_receipt_and_stock_history_is_unchanged_by_backfill(): void
    {
        $po = $this->makePo(50, 2, 50);
        $line = $po->details()->first();
        $stock = Inventory::create([
            'location_id' => $line->location_id, 'product_id' => $line->product_id,
            'quantity_on_hand' => 50, 'available_quantity' => 50,
        ]);
        $stockBefore = $stock->fresh()->getAttributes();
        $headersBefore = DB::table('receivings')->where('po_id', $po->po_id)->get()->toJson();
        DB::table('purchase_order_details')->where('po_detail_id', $line->po_detail_id)->update([
            'pieces_per_order_unit' => null, 'quantity_snapshot_source' => null,
        ]);
        $before = DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->get()->toJson();
        app(PoLifecycleBackfillService::class)->run();
        $this->assertSame($stockBefore, $stock->fresh()->getAttributes());
        $this->assertSame($headersBefore, DB::table('receivings')->where('po_id', $po->po_id)->get()->toJson());
        $this->assertSame($before, DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->get()->toJson());
        $this->assertSame('partially_received', $po->fresh()->lifecycle_status);
        $this->assertSame(0, (int) $line->fresh()->short_closed_quantity);
        $this->assertDatabaseHas('po_lifecycle_exceptions', [
            'po_detail_id' => $line->po_detail_id, 'code' => 'historical_package_size_unverified',
        ]);
    }

    public function test_overfilled_line_does_not_complete_another_line(): void
    {
        $po = $this->makePo(50, 2, 200);
        $line = $po->details()->first();
        $po->details()->create([
            'product_id' => $line->product_id, 'location_id' => $line->location_id,
            'quantity_ordered' => 2, 'unit_price' => '5.00', 'subtotal' => '10.00',
        ]);
        app(PoReceivingStatusService::class)->sync((int) $po->po_id);
        $this->assertSame(12, (int) $po->fresh()->status_id);
    }

    public function test_backfill_reports_unlinked_receipts_without_guessing_line(): void
    {
        $po = $this->makePo(50, 2, 50);
        $line = $po->details()->first();
        DB::table('receiving_details')->where('po_detail_id', $line->po_detail_id)->update(['po_detail_id' => null]);
        app(PoLifecycleBackfillService::class)->run();
        $this->assertDatabaseHas('po_lifecycle_exceptions', ['po_id' => $po->po_id, 'code' => 'receipt_requires_reconciliation']);
        $this->assertSame('open', $po->fresh()->lifecycle_status);
        $this->assertSame(0, app(PoLineBalanceService::class)->forPo($po)[$line->po_detail_id]['received']);
    }

    public function test_original_delivery_date_is_preserved(): void
    {
        $po = $this->makePo(50, 2, 0);
        $po->update(['expected_delivery_date' => '2026-10-20']);
        $po->update(['expected_delivery_date' => '2026-11-20']);
        $this->assertSame('2026-10-20', $po->fresh()->original_expected_delivery_date->toDateString());
    }
}
