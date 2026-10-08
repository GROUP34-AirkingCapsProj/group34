<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class InventoryBatchControllerTest extends TestCase
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

    public function test_list_returns_only_batches_with_remaining_stock_for_the_users_location(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $otherLocation = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Other Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
        ]);
        // Use a location-scoped role (warehouse_personnel=4), not the default
        // makeUser() role, which resolves to admin(1) — a centralized role
        // that sees every location by design (User::isCentralized()) and so
        // would never exercise the other-location exclusion below.
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => 4, 'location_id' => $location->location_id,
        ]);

        $svc = new InventoryBatchService();
        $rcv = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $withStock = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 5, now(), 'Aisle 1');

        $depletedBatch = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 3, now(), 'Aisle 2');
        $depletedBatch->quantity_remaining = 0;
        $depletedBatch->save();

        $svc->createBatch($product->product_id, $otherLocation->location_id, $rcv->receiving_id, 7, now(), 'Aisle 9');

        $res = $this->actingAs($user)->getJson('/api/inventory-batches');

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($withStock->batch_id, $data[0]['batch_id']);
        $this->assertSame('Aisle 1', $data[0]['zone_label']);
    }

    public function test_update_changes_zone_label_without_touching_quantities(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        $svc = new InventoryBatchService();
        $rcv = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 5, now());

        $res = $this->actingAs($user)->patchJson("/api/inventory-batches/{$batch->batch_id}", [
            'zone_label' => 'Aisle 4, Shelf C',
        ]);

        $res->assertOk();

        $fresh = $batch->fresh();
        $this->assertSame('Aisle 4, Shelf C', $fresh->zone_label);
        $this->assertSame(5, $fresh->quantity_received);
        $this->assertSame(5, $fresh->quantity_remaining);
    }

    public function test_update_allows_clearing_zone_label(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material', 'product_type' => 'installation_material',
        ]);
        $user = $this->makeUser();

        $svc = new InventoryBatchService();
        $rcv = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = $svc->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 5, now(), 'Aisle 1');

        $this->actingAs($user)->patchJson("/api/inventory-batches/{$batch->batch_id}", [
            'zone_label' => null,
        ])->assertOk();

        $this->assertNull($batch->fresh()->zone_label);
    }
}
