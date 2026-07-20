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
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use App\Services\InventoryBatchService;

class IssuanceBatchFifoTest extends TestCase
{
    use DatabaseTransactions;

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Loc',
            'location_type' => 'warehouse',
        ]);
    }

    private function makeAppliance(): Product
    {
        return Product::create([
            'product_code' => 'APP-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
            'unit_price'   => 100,
        ]);
    }

    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id'      => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now(),
        ]);
    }

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

    public function test_scanning_serialized_unit_deducts_its_exact_batch_and_stores_receiving_id(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user     = $this->makeUser();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 1, 'available_quantity' => 1, 'reorder_level' => 0,
        ]);

        $rcv   = $this->makeReceiving($location->location_id);
        $batch = (new InventoryBatchService())->createBatch($product->product_id, $location->location_id, $rcv->receiving_id, 1, now());

        $sn = 'SN-' . uniqid();
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcv->receiving_id,
            'serial_number' => $sn,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $sn,
        ]);

        $res->assertJson(['success' => true, 'action' => 'applied']);
        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        $detail = DB::table('issuance_details')->where('issuance_id', $issuance->issuance_id)->first();
        $this->assertSame($rcv->receiving_id, (int) $detail->receiving_id);
    }
}
