<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivingPackagesDeriveTest extends TestCase
{
    use DatabaseTransactions;

    public function test_receiving_a_package_does_not_reset_available_quantity_to_parity(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0,
        ]);

        // Pre-existing stock with 30 pieces reserved elsewhere.
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 100, 'packages_on_hand' => 1,
            'available_quantity' => 70, 'reorder_level' => 0,
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
            'barcode'          => 'SUP-' . uniqid(),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $inv = Inventory::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)->first();

        $this->assertSame(200, $inv->quantity_on_hand);
        $this->assertSame(2, $inv->packages_on_hand);
        $this->assertSame(170, $inv->available_quantity, 'available must rise by the +100 delta, not be reset to 200');
    }
}
