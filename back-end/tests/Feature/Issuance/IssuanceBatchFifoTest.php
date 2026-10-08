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
use App\Services\FifoPickingService;

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

        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 1,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $sn,
        ]);

        $res->assertJson(['success' => true, 'action' => 'applied']);
        $this->assertSame(0, $batch->fresh()->quantity_remaining);

        $detail = DB::table('issuance_details')->where('issuance_id', $issuance->issuance_id)->where('serial_number', $sn)->first();
        $this->assertSame($rcv->receiving_id, (int) $detail->receiving_id);
    }

    /**
     * Sets up a product with two batches at the same location: an older batch
     * (still has stock remaining) and a newer batch. Returns the location,
     * customer, user, issuance, and the two serials (older, newer).
     */
    private function makeTwoBatchFixture(): array
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user     = $this->makeUser();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 2, 'available_quantity' => 2, 'reorder_level' => 0,
        ]);

        $rcvOld = $this->makeReceiving($location->location_id);
        $batchOld = (new InventoryBatchService())->createBatch(
            $product->product_id, $location->location_id, $rcvOld->receiving_id, 1, now()->subDays(5)
        );

        $rcvNew = $this->makeReceiving($location->location_id);
        $batchNew = (new InventoryBatchService())->createBatch(
            $product->product_id, $location->location_id, $rcvNew->receiving_id, 1, now()
        );

        $snOld = 'SN-OLD-' . uniqid();
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcvOld->receiving_id,
            'serial_number' => $snOld,
            'serial_type'   => 'received',
            'status_id'     => 26,
        ]);

        $snNew = 'SN-NEW-' . uniqid();
        ItemSerial::create([
            'product_id'    => $product->product_id,
            'location_id'   => $location->location_id,
            'receiving_id'  => $rcvNew->receiving_id,
            'serial_number' => $snNew,
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

        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 2,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        return [$user, $issuance, $snOld, $snNew, $batchOld];
    }

    public function test_scanning_newer_batch_serial_is_rejected_as_fifo_violation(): void
    {
        [$user, $issuance, $snOld, $snNew, $batchOld] = $this->makeTwoBatchFixture();

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $snNew,
        ]);

        $res->assertStatus(422);
        $this->assertSame('FIFO_VIOLATION', $res->json('scan_result.status'));
        $this->assertTrue($res->json('scan_result.overridable'));
    }

    public function test_scanning_oldest_batch_serial_is_accepted(): void
    {
        [$user, $issuance, $snOld, $snNew] = $this->makeTwoBatchFixture();

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode'     => $snOld,
        ]);

        $res->assertJson(['success' => true, 'action' => 'applied']);
    }

    public function test_scanning_bulk_item_deducts_oldest_batch_first(): void
    {
        $location = $this->makeLocation();
        $product  = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Test Installation material',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user     = $this->makeUser();

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 10, 'available_quantity' => 10, 'reorder_level' => 0,
        ]);

        $svc = new InventoryBatchService();
        $old = $svc->createBatch($product->product_id, $location->location_id, $this->makeReceiving($location->location_id)->receiving_id, 6, now()->subDays(3));
        $new = $svc->createBatch($product->product_id, $location->location_id, $this->makeReceiving($location->location_id)->receiving_id, 4, now());

        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);

        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 8,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id'   => $issuance->issuance_id,
            'barcode'       => $product->product_code,
            'quantity'      => 8,
            'quantity_unit' => 'unit',
        ]);

        $res->assertJson(['success' => true, 'action' => 'applied']);
        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(2, $new->fresh()->quantity_remaining);
    }

    public function test_bulk_scan_cannot_deduct_more_than_the_order_has_remaining(): void
    {
        $location = $this->makeLocation();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Limited Installation material',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user = $this->makeUser();

        $inventory = Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 20, 'available_quantity' => 20, 'reorder_level' => 0,
        ]);
        $batch = (new InventoryBatchService())->createBatch(
            $product->product_id,
            $location->location_id,
            $this->makeReceiving($location->location_id)->receiving_id,
            20,
            now()
        );
        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);
        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 5,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode' => $product->product_code,
            'quantity' => 6,
            'quantity_unit' => 'piece',
        ])->assertStatus(422)->assertJson(['success' => false, 'action' => 'rejected']);

        $this->assertSame(20, (int) $inventory->fresh()->quantity_on_hand);
        $this->assertSame(20, (int) $batch->fresh()->quantity_remaining);
    }

    public function test_pick_uses_piece_price_and_does_not_double_count_order_row(): void
    {
        $location = $this->makeLocation();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Five Piece Box',
            'product_type' => 'installation_material', 'unit_price' => 50,
        ]);
        $product->installationMaterialDetail()->create([
            'pieces_per_package' => 5,
            'package_unit' => 'box',
        ]);
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);
        $user = $this->makeUser();
        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 10, 'available_quantity' => 10, 'reorder_level' => 0,
        ]);
        (new InventoryBatchService())->createBatch(
            $product->product_id,
            $location->location_id,
            $this->makeReceiving($location->location_id)->receiving_id,
            10,
            now()
        );
        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 40, 'discount_amount' => 0, 'total_amount' => 40,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);
        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 4,
            'quantity_issued' => 0, 'unit_price' => 10, 'subtotal' => 40,
        ]);

        $res = $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode' => $product->product_code,
            'quantity' => 4,
            'quantity_unit' => 'piece',
        ])->assertOk();

        $this->assertEqualsWithDelta(10.0, (float) $res->json('price_per_piece'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $res->json('subtotal'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $issuance->fresh()->subtotal, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $issuance->fresh()->total_amount, 0.001);
        $picked = DB::table('issuance_details')
            ->where('issuance_id', $issuance->issuance_id)
            ->where('quantity_issued', '>', 0)
            ->first();
        $this->assertSame(10, (int) $picked->stock_before);
        $this->assertSame(4, (int) $picked->quantity_deducted);
        $this->assertSame(6, (int) $picked->stock_after);
        $this->assertNotNull($picked->batch_id);
    }

    public function test_dated_oldest_batch_fulfils_pick_before_undated_legacy_batch(): void
    {
        $location = $this->makeLocation();
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(),
            'product_name' => 'FIFO Material',
            'product_type' => 'installation_material',
            'unit_price' => 50,
        ]);

        // This legacy/adjustment row used to sort first in MySQL because its
        // received_at is NULL, incorrectly producing a 1 + 3 split by zone.
        InventoryBatch::create([
            'product_id' => $product->product_id,
            'location_id' => $location->location_id,
            'receiving_id' => null,
            'quantity_received' => 0,
            'quantity_remaining' => 1,
            'zone_label' => null,
            'received_at' => null,
        ]);
        (new InventoryBatchService())->createBatch(
            $product->product_id,
            $location->location_id,
            $this->makeReceiving($location->location_id)->receiving_id,
            10,
            now()->subDays(2),
            'Location 1'
        );

        $plan = app(FifoPickingService::class)->suggest(
            $location->location_id,
            [['product_id' => $product->product_id, 'quantity' => 4]]
        );

        $this->assertCount(1, $plan[0]['suggestions']);
        $this->assertSame('Location 1', $plan[0]['suggestions'][0]['zone_label']);
        $this->assertSame(4, $plan[0]['suggestions'][0]['quantity']);
    }
}
