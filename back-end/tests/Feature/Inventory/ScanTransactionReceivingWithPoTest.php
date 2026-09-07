<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;

class ScanTransactionReceivingWithPoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scan_transaction_receiving_with_po_creates_batch_linked_to_the_po_and_stores_zone_label(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->product_code,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
            'quantity'         => 1,
            'zone_label'       => 'Aisle 5, Rack C',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertNotNull($batch, 'Expected a batch to be created for this PO-based receiving scan.');
        $this->assertSame('Aisle 5, Rack C', $batch->zone_label);
        $this->assertNotNull($batch->receiving_id);
        $this->assertGreaterThan(0, $batch->quantity_received);
        $this->assertSame($batch->quantity_received, $batch->quantity_remaining);

        // The batch's receiving_id must actually be linked to this PO.
        $receivingPoId = DB::table('receivings')->where('receiving_id', $batch->receiving_id)->value('po_id');
        $this->assertSame($po->po_id, $receivingPoId);
    }
}
