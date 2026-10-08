<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\InventoryBatch;
use Illuminate\Support\Facades\DB;

class ReceivingCreatesBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_store_creates_one_inventory_batch_per_receiving_line(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'Test Installation material',
            'product_type' => 'installation_material',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username'      => 'tester_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number'           => 'PO-' . uniqid(),
            'location_id'         => $location->location_id,
            'verification_status' => 'verified',
            'status_id'           => 1,
        ]);

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [
                ['product_id' => $product->product_id, 'quantity_amount' => 20, 'condition' => 'Good'],
            ],
        ])->assertStatus(201);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame(20, $batch->quantity_received);
        $this->assertSame(20, $batch->quantity_remaining);
        $this->assertNotNull($batch->receiving_id);
    }
}
