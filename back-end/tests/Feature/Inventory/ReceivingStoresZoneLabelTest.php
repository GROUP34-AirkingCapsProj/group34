<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;

class ReceivingStoresZoneLabelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_store_saves_zone_label_onto_the_created_batch(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
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

        $po->details()->create(['product_id' => $product->product_id, 'location_id' => $location->location_id, 'quantity_ordered' => 20, 'unit_price' => '1.00']);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id' => $po->po_id,
            'location_id' => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => now()->toDateString(),
            'details' => [
                ['product_id' => $product->product_id, 'quantity_amount' => 10, 'condition' => 'Good', 'zone_label' => 'Aisle 3, Shelf B'],
            ],
        ])->assertStatus(201);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertSame('Aisle 3, Shelf B', $batch->zone_label);
    }

    public function test_store_allows_omitting_zone_label(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
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

        $po->details()->create(['product_id' => $product->product_id, 'location_id' => $location->location_id, 'quantity_ordered' => 20, 'unit_price' => '1.00']);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id' => $po->po_id,
            'location_id' => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => now()->toDateString(),
            'details' => [
                ['product_id' => $product->product_id, 'quantity_amount' => 10, 'condition' => 'Good'],
            ],
        ])->assertStatus(201);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertNull($batch->zone_label);
    }

    public function test_store_rejects_zone_label_over_100_characters(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
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

        $po->details()->create(['product_id' => $product->product_id, 'location_id' => $location->location_id, 'quantity_ordered' => 20, 'unit_price' => '1.00']);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id' => $po->po_id,
            'location_id' => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => now()->toDateString(),
            'details' => [
                ['product_id' => $product->product_id, 'quantity_amount' => 10, 'condition' => 'Good', 'zone_label' => str_repeat('A', 101)],
            ],
        ])->assertStatus(422);
    }
}
