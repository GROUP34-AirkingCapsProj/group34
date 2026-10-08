<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\ApplianceDetail;
use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Models\User;

class ReceiveCountTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    public function test_counting_loose_pieces_produces_one_tag_per_batch(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
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

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 473,
            'zone_label'   => 'A-03',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.quantity_received', 473);

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->first();
        $this->assertNotNull($inventory);
        $this->assertSame(473, (int) $inventory->quantity_on_hand);
        $this->assertSame(4, (int) $inventory->packages_on_hand);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();
        $this->assertNotNull($batch);
        $this->assertSame(473, (int) $batch->quantity_remaining);
        $this->assertSame('A-03', $batch->zone_label);

        $tagCount = ItemSerial::where('batch_id', $batch->batch_id)->count();
        $this->assertSame(1, $tagCount, 'Piece-granularity counted receiving must mint one tag per batch, not one per piece.');

        $detail = ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('counted', $detail->entry_mode);
    }

    public function test_counting_packages_converts_to_pieces_and_mints_one_tag_per_package(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Wire Reel',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Reel', 'is_serialized' => 0, 'count_unit' => 'package',
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

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 6,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');
        $res->assertJsonPath('data.quantity_received', 300);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();
        $this->assertNotNull($batch);

        $tagCount = ItemSerial::where('batch_id', $batch->batch_id)->count();
        $this->assertSame(6, $tagCount);
    }

    public function test_serialized_appliance_can_never_be_count_received(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'APL-' . uniqid(), 'product_name' => 'Split Type Aircon',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create([
            'product_id' => $product->product_id, 'is_serialized' => 1,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 5,
            'unit_price' => 500, 'subtotal' => 2500,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'rejected');

        $inventoryExists = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->exists();
        $this->assertFalse(
            $inventoryExists,
            'Serialized appliances must never move stock via count-receiving — without this guard, an appliance could be count-received and lose its per-unit serial trail, silently breaking warranty lookup and incident reports.'
        );
    }

    public function test_over_receipt_is_refused_and_posts_nothing(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Screw Pack',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Pack', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 1,
            'unit_price' => 5, 'subtotal' => 5,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 55,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'over_receipt');
        $res->assertJsonPath('data.remaining', 50);
        $res->assertJsonPath('data.overage', 5);

        $inventoryExists = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->exists();
        $this->assertFalse($inventoryExists);
    }

    public function test_accepting_overage_posts_full_counted_quantity_and_completes_po(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Screw Pack',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Pack', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 1,
            'unit_price' => 5, 'subtotal' => 5,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'   => $poDetail->po_detail_id,
            'location_id'    => $location->location_id,
            'quantity'       => 55,
            'accept_overage' => true,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->first();
        $this->assertNotNull($inventory);
        $this->assertSame(55, (int) $inventory->quantity_on_hand, 'Over-received quantity must not be clamped to the ordered amount.');

        $po->refresh();
        $this->assertSame(11, (int) $po->status_id, 'An over-received PO must reach Completed status, not hang open.');
    }

    public function test_unverified_po_is_rejected_and_no_stock_moves(): void
    {
        $user = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'pending', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 100,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'rejected');

        $inventoryExists = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->exists();
        $this->assertFalse($inventoryExists);
    }
}
