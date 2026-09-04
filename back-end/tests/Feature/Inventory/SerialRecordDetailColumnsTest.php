<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
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
 * A material tag carries no quantity of its own, so the Serial Numbers table
 * used to render six columns that were blank on every single row. The tag does
 * know two things it was never asked for: which batch it resolves to, and what
 * one tag physically stands for. Both endpoints that feed that table must ship
 * them, and the PO group endpoint must ship the same per-serial shape as the
 * per-SKU one so the two screens can share a table.
 */
class SerialRecordDetailColumnsTest extends TestCase
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
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 10,
            'package_unit' => 'Roll', 'is_serialized' => 0, 'count_unit' => $countUnit,
        ]);

        return $product;
    }

    private function makePoDetail(Location $location, Product $product): PurchaseOrderDetail
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8, 'order_date' => now()->toDateString(),
        ]);

        return PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 100,
            'unit_price' => 5, 'subtotal' => 500,
        ]);
    }

    private function receiveByCount(User $user, PurchaseOrderDetail $poDetail, Location $location, int $qty): void
    {
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => $qty,
        ])->assertOk();
    }

    private function serialRecords(User $user, Location $location, Product $product): array
    {
        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();

        return $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');
    }

    public function test_material_tag_rows_carry_their_batch_and_granularity(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->receiveByCount($user, $poDetail, $location, 4);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->firstOrFail();

        $records = $this->serialRecords($user, $location, $product);
        $this->assertCount(4, $records);

        foreach ($records as $r) {
            $this->assertSame($batch->batch_id, $r['batch_id']);
            $this->assertSame((int) $batch->quantity_received, (int) $r['batch_quantity_received']);
            $this->assertSame((int) $batch->quantity_remaining, (int) $r['batch_quantity_remaining']);
            // One tag per package, so the row can say what it stands for even
            // though it still reports no quantity of its own.
            $this->assertSame('package', $r['count_unit']);
            $this->assertSame(10, (int) $r['pieces_per_package']);
            $this->assertNull($r['row_quantity_received']);
        }
    }

    /** A lot counted by the piece mints one tag for the whole lot. */
    public function test_a_piece_counted_lot_reports_its_granularity_as_piece(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('piece');
        $poDetail = $this->makePoDetail($location, $product);

        $this->receiveByCount($user, $poDetail, $location, 30);

        $records = $this->serialRecords($user, $location, $product);

        $this->assertCount(1, $records, 'A piece-counted lot mints exactly one tag.');
        $this->assertSame('piece', $records[0]['count_unit']);
        $this->assertNotNull($records[0]['batch_id']);
    }

    /**
     * Tags minted before item_serial.batch_id existed carry no batch link. The
     * receipt still books one batch per product per location, so the batch is
     * recoverable - and without that fallback the oldest tags in the system
     * would be the only rows left with a blank batch column.
     */
    public function test_a_tag_with_no_batch_link_recovers_its_batch_from_the_receiving(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->receiveByCount($user, $poDetail, $location, 2);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->firstOrFail();

        ItemSerial::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->update(['batch_id' => null]);

        $records = $this->serialRecords($user, $location, $product);

        $this->assertCount(2, $records);
        foreach ($records as $r) {
            $this->assertSame($batch->batch_id, $r['batch_id'], 'The batch must be recovered from the receiving.');
            $this->assertSame((int) $batch->quantity_received, (int) $r['batch_quantity_received']);
        }
    }

    /** The appliance branch reports per-unit counts and no tag metadata. */
    public function test_appliance_rows_are_untouched_by_the_material_columns(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type 1.5HP',
            'product_type' => 'appliance',
        ]);
        $inventory = Inventory::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'quantity_on_hand' => 1, 'available_quantity' => 1,
        ]);
        ItemSerial::create([
            'product_id' => $product->product_id, 'location_id' => $location->location_id,
            'serial_number' => 'SN-' . uniqid(), 'serial_type' => 'received', 'status_id' => 26,
        ]);

        $records = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.serial_records');

        $this->assertCount(1, $records);
        $this->assertSame(1, $records[0]['row_quantity_received']);
        $this->assertArrayNotHasKey('count_unit', $records[0]);
        $this->assertArrayNotHasKey('batch_id', $records[0]);
    }

    /**
     * The Inventory Batch modal renders the same table, so its rows must carry
     * the same fields the per-SKU rows do - otherwise the shared table quietly
     * drops columns on one screen and not the other.
     */
    public function test_po_group_serials_carry_the_same_per_serial_detail(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->receiveByCount($user, $poDetail, $location, 3);

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->firstOrFail();

        $serials = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $poDetail->po_id . '?location_id=' . $location->location_id)
            ->assertStatus(200)
            ->json('data.serials');

        $this->assertCount(3, $serials);
        foreach ($serials as $s) {
            $this->assertTrue($s['is_material_tag'], 'A minted tag is scannable off the screen.');
            $this->assertNull($s['supplier_barcode'], 'A generated tag is ours to reprint.');
            $this->assertSame('PE Foam', $s['product_name']);
            $this->assertSame($batch->batch_id, $s['batch_id']);
            $this->assertSame((int) $batch->quantity_received, (int) $s['batch_quantity_received']);
            $this->assertSame('package', $s['count_unit']);
            $this->assertNotNull($s['receiving_number']);
            $this->assertNotNull($s['scanned_by_name']);
            $this->assertNull($s['row_quantity_received']);
        }
    }

    /**
     * An appliance serial is the manufacturer's own. The PO group rows must say
     * so, or the shared table would offer a barcode and a reprint button for a
     * tag we never minted.
     */
    public function test_po_group_marks_an_appliance_serial_as_not_our_tag(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Aircon',
            'product_type' => 'appliance',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 1, 'order_date' => now()->toDateString(),
        ]);
        $serial = 'SN-' . uniqid();

        $this->actingAs($user)->postJson('/api/receivings', [
            'pc_id'            => $po->po_id,
            'location_id'      => $location->location_id,
            'receiving_number' => 'RCV-' . uniqid(),
            'receiving_date'   => now()->toDateString(),
            'details'          => [[
                'product_id'      => $product->product_id,
                'quantity_amount' => 1,
                'condition'       => 'Good',
                'scanned_barcode' => $serial,
                'zone_label'      => 'Location 1',
            ]],
        ])->assertStatus(201);

        $serials = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $po->po_id . '?location_id=' . $location->location_id)
            ->assertStatus(200)
            ->json('data.serials');

        $row = collect($serials)->firstWhere('serial_number', $serial);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_material_tag']);
        $this->assertNull($row['count_unit']);
        $this->assertSame(1, $row['row_quantity_received']);
    }
}
