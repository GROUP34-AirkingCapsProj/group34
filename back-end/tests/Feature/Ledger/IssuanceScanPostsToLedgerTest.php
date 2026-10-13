<?php

namespace Tests\Feature\Ledger;

use App\Models\Customer;
use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\InventoryLedgerEntry;
use App\Models\Issuance;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scan paths issue stock out just as surely as the form path does, so they owe
 * the ledger the same evidence. These are separate from IssuancePostsToLedgerTest
 * because IssuanceScanController is a different write path with its own batch
 * resolution — a serialized unit deducts its exact batch, while a material runs
 * FIFO and can span several.
 */
class IssuanceScanPostsToLedgerTest extends TestCase
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

    private function makeUser(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 'scan_' . uniqid(), 'first_name' => 'Scan', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    private function makeReceiving(int $locationId): Receiving
    {
        return Receiving::create([
            'location_id' => $locationId,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date' => now(),
        ]);
    }

    private function makeIssuance(Location $location, User $user): Issuance
    {
        $customer = Customer::create(['customer_name' => 'Test Customer', 'customer_type' => 'individual']);

        return Issuance::create([
            'location_id' => $location->location_id, 'customer_id' => $customer->customer_id,
            'issuance_date' => now()->toDateString(), 'issuance_type' => 'individual',
            'dr_number' => 'DR-TEST-' . uniqid(), 'discount_type' => 'none', 'discount_percent' => 0,
            'subtotal' => 0, 'discount_amount' => 0, 'total_amount' => 0,
            'issued_by' => $user->user_id, 'status_id' => 30,
        ]);
    }

    // ---------------------------------------------------------------- serialized

    /** @return array{0: Issuance, 1: Product, 2: User, 3: Location, 4: string} */
    private function serializedFixture(float $unitCost): array
    {
        $location = $this->makeLocation();
        $user = $this->makeUser();
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance', 'unit_price' => 100,
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 1, 'available_quantity' => 1, 'reorder_level' => 0,
        ]);

        $rcv = $this->makeReceiving($location->location_id);
        InventoryBatch::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => $rcv->receiving_id, 'quantity_received' => 1, 'quantity_remaining' => 1,
            'received_at' => now(), 'unit_cost' => $unitCost, 'cost_basis' => 'actual',
        ]);

        $sn = 'SN-' . uniqid();
        ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'receiving_id' => $rcv->receiving_id, 'serial_number' => $sn,
            'serial_type' => 'received', 'status_id' => 26,
        ]);

        $issuance = $this->makeIssuance($location, $user);
        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 1,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        return [$issuance, $product, $user, $location, $sn];
    }

    public function test_scanning_a_serialized_unit_out_posts_one_row_carrying_the_serial(): void
    {
        [$issuance, $product, $user, $location, $sn] = $this->serializedFixture(4500.00);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode' => $sn,
        ])->assertJson(['success' => true, 'action' => 'applied']);

        $row = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $issuance->issuance_id)
            ->firstOrFail();

        $this->assertSame('out', $row->direction);
        $this->assertSame('issuance', $row->movement_type);
        $this->assertSame(1, (int) $row->quantity);
        $this->assertSame($sn, $row->serial_number);
        $this->assertSame((int) $product->product_id, (int) $row->product_id);
        $this->assertSame((int) $location->location_id, (int) $row->from_location_id);
        // The batch was resolved before the serial row was deleted, so its cost
        // survives into the ledger rather than being lost with the row.
        $this->assertSame('4500.00', $row->unit_cost);
        $this->assertSame('4500.00', $row->extended_value);
        $this->assertNotNull($row->batch_id);
    }

    public function test_a_serialized_unit_leaves_exactly_one_ledger_row_however_often_it_is_scanned(): void
    {
        [$issuance, , $user, , $sn] = $this->serializedFixture(4500.00);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id, 'barcode' => $sn,
        ])->assertJson(['success' => true]);

        // The unit is gone from stock, so the second scan cannot apply. Whatever it
        // answers, the ledger must still show that unit leaving once and only once.
        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id, 'barcode' => $sn,
        ]);

        $this->assertSame(
            1,
            InventoryLedgerEntry::where('source_type', 'issuances')
                ->where('source_id', $issuance->issuance_id)
                ->where('serial_number', $sn)
                ->count()
        );
    }

    // ----------------------------------------------------------------- materials

    public function test_scanning_a_material_posts_a_row_per_batch_slice_at_its_own_cost(): void
    {
        $location = $this->makeLocation();
        $user = $this->makeUser();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 1,
            'package_unit' => 'Roll', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);

        Inventory::create([
            'location_id' => $location->location_id, 'product_id' => $product->product_id,
            'quantity_on_hand' => 10, 'available_quantity' => 10, 'reorder_level' => 0,
        ]);

        // Older batch at 100, newer at 200. A 6-piece pick takes 4 then 2.
        foreach ([[4, 100.00, '2026-01-01'], [6, 200.00, '2026-06-01']] as [$qty, $cost, $date]) {
            $rcv = $this->makeReceiving($location->location_id);
            InventoryBatch::create([
                'product_id' => $product->product_id, 'location_id' => $location->location_id,
                'receiving_id' => $rcv->receiving_id, 'quantity_received' => $qty,
                'quantity_remaining' => $qty, 'received_at' => $date,
                'unit_cost' => $cost, 'cost_basis' => 'actual',
            ]);
        }

        $issuance = $this->makeIssuance($location, $user);
        // The scanner refuses anything not on the order, so the line has to exist
        // before it can be picked against.
        $issuance->details()->create([
            'product_id' => $product->product_id, 'quantity_requested' => 6,
            'quantity_issued' => 0, 'unit_price' => 0, 'subtotal' => 0,
        ]);

        $this->actingAs($user)->postJson('/api/issuance-scan/scan', [
            'issuance_id' => $issuance->issuance_id,
            'barcode' => $product->product_code,
            'quantity' => 6,
        ])->assertJson(['success' => true, 'action' => 'applied']);

        $rows = InventoryLedgerEntry::where('source_type', 'issuances')
            ->where('source_id', $issuance->issuance_id)
            ->orderBy('unit_cost')
            ->get();

        $this->assertCount(2, $rows, 'A 6-piece pick over a 4-piece oldest batch is two slices.');
        $this->assertSame([$product->product_code, $product->product_code], $rows->pluck('serial_number')->all());
        $picked = $issuance->details()->where('quantity_issued', '>', 0)->firstOrFail();
        $this->assertSame($product->product_code, $picked->serial_number);
        $this->assertDatabaseHas('inventory_scan_events', [
            'product_id' => $product->product_id,
            'transaction_type' => 'issuance',
            'barcode' => $product->product_code,
            'serial_number' => $product->product_code,
        ]);

        $this->assertSame([4, 2], $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(['100.00', '200.00'], $rows->pluck('unit_cost')->all());
        // 4x100 + 2x200 = 800 of stock out, not 6 x one averaged price.
        $this->assertSame(800.0, (float) $rows->sum(fn ($r) => (float) $r->extended_value));
        $this->assertCount(2, $rows->pluck('posting_key')->unique());
    }
}
