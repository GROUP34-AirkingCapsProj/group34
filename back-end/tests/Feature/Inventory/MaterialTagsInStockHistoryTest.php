<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The inventory detail panel (GET /api/inventory/{id}/history) must list the
 * digital tags minted for installation materials, so a picker whose physical
 * label was damaged can look the item up and reprint it. Before this, the
 * serial listing was gated on $isSerialized and materials got nothing back.
 */
class MaterialTagsInStockHistoryTest extends TestCase
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

    private function makeLocation(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
    }

    private function makeMaterial(string $countUnit): Product
    {
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 10,
            'package_unit' => 'Roll', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);

        return $product;
    }

    private function makePoDetail(Location $location, Product $product): PurchaseOrderDetail
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 100,
            'unit_price' => 5, 'subtotal' => 500,
        ]);
    }

    private function historyFor(User $user, Location $location, Product $product): array
    {
        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();

        return $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data');
    }

    /** Regression guard for the reported bug. */
    public function test_counted_material_receipt_surfaces_its_generated_tags(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 4,
        ])->assertOk();

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->firstOrFail();
        $minted = ItemSerial::where('batch_id', $batch->batch_id)
            ->pluck('serial_number')->sort()->values()->all();
        $this->assertCount(4, $minted, 'Sanity: four packages counted must mint four tags.');

        $data    = $this->historyFor($user, $location, $product);
        $records = $data['serial_records'];

        $listed = collect($records)->pluck('serial_number')->sort()->values()->all();
        $this->assertSame($minted, $listed, 'Every minted tag must appear in serial_records.');
        $this->assertSame(4, $data['summary']['serial_count']);

        foreach ($records as $r) {
            $this->assertTrue($r['is_material_tag']);
            $this->assertStringStartsWith('INT-', $r['serial_number']);
            $this->assertArrayHasKey('supplier_barcode', $r);
            $this->assertNotNull($r['received_at']);
            // A tag carries no quantity of its own - the columns stay blank.
            $this->assertNull($r['row_quantity_received']);
            $this->assertNull($r['row_stock_in']);
            $this->assertNull($r['row_stock_out']);
            $this->assertNull($r['row_transfer']);
        }
    }

    public function test_scan_path_records_one_row_keyed_by_the_supplier_code(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('piece');
        $poDetail = $this->makePoDetail($location, $product);
        $barcode  = 'SUP-' . strtoupper(uniqid());

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $barcode,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $poDetail->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk();

        $records = $this->historyFor($user, $location, $product)['serial_records'];

        $this->assertCount(1, $records);
        // Design change: a generated barcode exists only because an item
        // arrived without one. A scanned supplier code IS the identifier now,
        // so serial_number carries the scanned code verbatim instead of a
        // separately-minted INT- tag.
        $this->assertSame($barcode, $records[0]['serial_number']);
        $this->assertSame($barcode, $records[0]['supplier_barcode']);
        $this->assertTrue($records[0]['is_material_tag']);
    }

    /**
     * Materials are picked by batch, not by tag - FifoPickingService writes a
     * null serial_number on every installation material pick - so issuance_details holds
     * no per-tag serial to union in. Guarded so a future change is deliberate.
     */
    public function test_material_issuance_records_no_per_tag_serial(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('piece');
        $poDetail = $this->makePoDetail($location, $product);

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 30,
        ])->assertOk();

        $issuance = Issuance::create([
            'location_id'   => $location->location_id,
            'issuance_date' => now()->toDateString(),
            'issued_by'     => $user->user_id,
            'status_id'     => 1,
        ]);
        IssuanceDetail::create([
            'issuance_id'      => $issuance->issuance_id,
            'product_id'       => $product->product_id,
            'quantity_issued'  => 5,
            'quantity_in_pieces' => 5,
        ]);

        $records = $this->historyFor($user, $location, $product)['serial_records'];

        $this->assertCount(1, $records, 'Only the minted tag is listed; no fabricated issued row.');
        $this->assertTrue($records[0]['is_material_tag']);
    }

    /** Collateral-damage guard: the appliance branch is untouched. */
    public function test_appliance_serial_records_are_unchanged(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type 1.5HP',
            'product_type' => 'appliance',
        ]);
        $inventory = Inventory::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_on_hand' => 2, 'available_quantity' => 2,
        ]);

        ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'serial_number' => 'SN-IN-' . uniqid(), 'serial_type' => 'received', 'status_id' => 26,
        ]);
        $issuedSerial = 'SN-OUT-' . uniqid();
        $issuance = Issuance::create([
            'location_id' => $location->location_id, 'issuance_date' => now()->toDateString(),
            'issued_by' => $user->user_id, 'status_id' => 1,
        ]);
        IssuanceDetail::create([
            'issuance_id' => $issuance->issuance_id, 'product_id' => $product->product_id,
            'serial_number' => $issuedSerial, 'quantity_issued' => 1,
        ]);

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        $this->assertCount(2, $records);
        foreach ($records as $r) {
            $this->assertSame(1, $r['row_quantity_received']);
            $this->assertArrayNotHasKey('is_material_tag', $r);
        }
        $issued = collect($records)->firstWhere('serial_number', $issuedSerial);
        $this->assertSame(0, $issued['row_stock_in']);
        $this->assertSame(1, $issued['row_stock_out']);
    }
}
