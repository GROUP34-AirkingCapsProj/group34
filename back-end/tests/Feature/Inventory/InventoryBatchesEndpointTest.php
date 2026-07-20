<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class InventoryBatchesEndpointTest extends TestCase
{
    use DatabaseTransactions;

    public function test_batches_endpoint_returns_batches_oldest_first_with_legacy_last(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Consumable', 'product_type' => 'installation_material',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        $inv = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 15, 'available_quantity' => 15, 'reorder_level' => 0,
        ]);

        $svc = new InventoryBatchService();
        $rcv1 = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()->subDays(5)]);
        $rcv2 = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $svc->createBatch($product->product_id, $location->location_id, $rcv1->receiving_id, 5, now()->subDays(5));
        $svc->createBatch($product->product_id, $location->location_id, $rcv2->receiving_id, 10, now());

        $res = $this->actingAs($user)->getJson("/api/inventory/{$inv->inventory_id}/batches");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($rcv1->receiving_id, $data[0]['receiving_id']);
        $this->assertSame($rcv2->receiving_id, $data[1]['receiving_id']);
    }
}
