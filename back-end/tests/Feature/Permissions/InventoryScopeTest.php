<?php

namespace Tests\Feature\Permissions;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scoped_user_only_sees_their_location_inventory(): void
    {
        // Insert two locations directly (is_active and location_code not in fillable)
        $uid = uniqid();
        $locAId = (int) DB::table('locations')->insertGetId([
            'location_name' => 'LocA_' . $uid,
            'location_code' => 'TST-A-' . $uid,
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $locBId = (int) DB::table('locations')->insertGetId([
            'location_name' => 'LocB_' . $uid,
            'location_code' => 'TST-B-' . $uid,
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Use an existing product (avoids needing to satisfy all NOT NULL columns)
        $product = Product::first();
        $this->assertNotNull($product, 'Seed at least one product before running this test.');

        Inventory::create([
            'product_id'      => $product->product_id,
            'location_id'     => $locAId,
            'quantity_on_hand' => 5,
        ]);
        Inventory::create([
            'product_id'      => $product->product_id,
            'location_id'     => $locBId,
            'quantity_on_hand' => 9,
        ]);

        $wp = User::create([
            'username'      => 'iv_' . uniqid(),
            'first_name'    => 'I',
            'last_name'     => 'V',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => 4,
            'location_id'   => $locAId,
        ]);
        $res = $this->actingAs($wp, 'sanctum')->getJson('/api/inventory');
        $res->assertOk();

        // paginated() returns rows under 'data' (not 'data.data')
        $locationIds = collect($res->json('data'))->pluck('location_id')->unique()->values()->all();
        $this->assertEquals([$locAId], $locationIds);
    }
}
