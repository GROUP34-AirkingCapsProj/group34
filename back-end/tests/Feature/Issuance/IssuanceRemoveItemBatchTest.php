<?php

namespace Tests\Feature\Issuance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class IssuanceRemoveItemBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_removing_serialized_item_restores_batch_and_recreates_serial_with_receiving_id(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Test Appliance',
            'product_type' => 'appliance', 'unit_price' => 100,
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);

        $rcv   = Receiving::create(['location_id' => $location->location_id, 'receiving_number' => 'RCV-' . uniqid(), 'receiving_date' => now()]);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 1, now());
        $batch->quantity_remaining = 0;
        $batch->save();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0,
        ]);

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 100, 'discount_amount' => 0, 'total_amount' => 100,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);

        $sn = 'SN-' . uniqid();
        $detail = IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'serial_number' => $sn, 'receiving_id' => $rcv->receiving_id,
            'unit_price' => 100, 'quantity_issued' => 1, 'quantity_unit' => 'unit',
            'quantity_in_pieces' => 1, 'unit_price_per_piece' => 100, 'subtotal' => 100,
            'condition_issued' => 'Good',
        ]);

        $this->actingAs($user)->deleteJson("/api/issuance-scan/item/{$detail->issuance_detail_id}")
            ->assertJson(['success' => true]);

        $this->assertSame(1, $batch->fresh()->quantity_remaining);

        $serial = ItemSerial::where('serial_number', $sn)->first();
        $this->assertNotNull($serial);
        $this->assertSame($rcv->receiving_id, $serial->receiving_id);
    }
}
