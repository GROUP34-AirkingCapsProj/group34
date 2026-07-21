<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\InventoryBatch;

class ReceivingZonePersistsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        return User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
    }

    public function test_zone_label_from_scan_receiving_persists_to_batch_and_console(): void
    {
        $wh = Location::create(['location_code' => 'WH-' . uniqid(), 'location_name' => 'Main WH', 'location_type' => 'warehouse']);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Wire',
            'product_type' => 'installation_material',
            'barcode'      => 'BAR' . random_int(1000000000000, 9999999999999),
        ]);
        $user = $this->makeUser();

        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $wh->location_id,
            'verification_status' => 'verified',
            'status_id'           => 8, // Approved
            'order_date'          => now()->toDateString(),
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id'            => $po->po_id,
            'product_id'       => $product->product_id,
            'quantity_ordered' => 10,
            'unit_price'       => 1,
            'subtotal'         => 10,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->barcode,
            'transaction_type' => 'receiving',
            'location_id'      => $wh->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
            'quantity'         => 1,
            'zone_label'       => 'Zone A',
        ]);
        $res->assertStatus(200);
        $this->assertNotSame('rejected', $res->json('data.action'), 'Receiving scan was rejected: ' . json_encode($res->json('data')));

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $wh->location_id)
            ->whereNotNull('receiving_id')
            ->latest('batch_id')
            ->first();
        $this->assertNotNull($batch, 'No batch was created for the received product.');
        $this->assertSame('Zone A', $batch->zone_label);

        $groups = $this->actingAs($user)->getJson('/api/po-inventory-groups')->assertStatus(200)->json('data');
        $mine = collect($groups)->firstWhere('po_id', $po->po_id);
        $this->assertNotNull($mine);
        $this->assertSame('Zone A', $mine['location_label']);
    }
}
