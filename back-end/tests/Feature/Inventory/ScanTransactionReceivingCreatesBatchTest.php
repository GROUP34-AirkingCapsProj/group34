<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

class ScanTransactionReceivingCreatesBatchTest extends TestCase
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

    public function test_scan_transaction_receiving_without_po_creates_a_batch_with_zone_label(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $product->product_code,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'zone_label'       => 'Aisle 3, Shelf B',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame('Aisle 3, Shelf B', $batch->zone_label);
        $this->assertGreaterThan(0, $batch->quantity_received);
        $this->assertSame($batch->quantity_received, $batch->quantity_remaining);
    }

    public function test_scan_transaction_receiving_accumulates_into_the_same_batch_across_repeated_scans(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable',
            'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        // Two separate scans of the same product/location on the same day (no PO) —
        // should accumulate into ONE batch row, not create two.
        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode' => $product->product_code, 'transaction_type' => 'receiving',
            'location_id' => $location->location_id,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode' => $product->product_code, 'transaction_type' => 'receiving',
            'location_id' => $location->location_id,
        ])->assertOk();

        $batches = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->get();

        $this->assertCount(1, $batches);
        $batch = $batches->first();
        $this->assertSame($batch->quantity_received, $batch->quantity_remaining);
        $this->assertGreaterThan(0, $batch->quantity_received);
    }
}
