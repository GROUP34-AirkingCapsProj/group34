<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\Receiving;
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
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp, 'is_serialized' => 0,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $orderedPackages,
            'unit_price' => 5, 'subtotal' => 50,
        ]);
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()->toDateString(),
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
                . 'Completed, not stuck open — do not "fix" it to =='
        );
    }
}
