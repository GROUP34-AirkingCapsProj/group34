<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
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
 * The Stock-In History tab renders one row per receiving_details row. Its
 * identifier column used to render `scanned_barcode` alone, which is null for
 * a counted line — so a counted receipt read as though nothing was recorded,
 * even though tags had been minted for it.
 *
 * Every stock-in row must therefore carry the identifiers that belong to it:
 * the supplier barcode when there was one, and the generated tags minted for
 * that line.
 *
 * ATTRIBUTION. item_serial links a tag to a receiving and a batch, never to a
 * single receiving_details row, so tags are attributed by the pair the data
 * *can* support: (receiving, product) narrowed by supplier_barcode. A scanned
 * line's tag carries that exact barcode; counted lines mint tags with a null
 * supplier_barcode. When one receiving holds several counted lines for the
 * same product the null-barcode tags belong to that group as a whole and not
 * to any one line — the payload says so via `tag_attribution` = 'shared' and
 * `tag_line_count`, rather than pretending to a precision the data lacks.
 */
class StockInHistoryShowsTagsTest extends TestCase
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

    private function makeMaterial(string $countUnit = 'package'): Product
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

    private function countInto(User $user, PurchaseOrderDetail $poDetail, Location $location, int $qty): void
    {
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => $qty,
        ])->assertOk();
    }

    private function scanInto(User $user, PurchaseOrderDetail $poDetail, Location $location, string $barcode): void
    {
        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $barcode,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $poDetail->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk();
    }

    /** The reported bug: a counted stock-in row rendered empty. */
    public function test_counted_stock_in_row_exposes_its_generated_tags(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->countInto($user, $poDetail, $location, 2);

        $minted = ItemSerial::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->pluck('serial_number')->sort()->values()->all();
        $this->assertCount(2, $minted, 'Sanity: two counted packages mint two tags.');

        $rows = $this->historyFor($user, $location, $product)['receiving_history'];
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertNull($row['scanned_barcode'], 'A counted line has no supplier barcode.');
        $this->assertSame(2, $row['generated_tag_count']);
        $this->assertSame($minted, collect($row['generated_tags'])->sort()->values()->all());
        $this->assertSame('exact', $row['tag_attribution']);
        $this->assertSame(1, $row['tag_line_count']);
    }

    /**
     * The exact reported scenario: two counted boxes plus one scanned box.
     * The scanned line keeps its own tag; the counted line keeps the other
     * two. No serial appears on both rows.
     */
    public function test_mixed_receipt_splits_tags_between_the_scanned_and_counted_lines(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);
        $barcode  = 'SUP-' . strtoupper(uniqid());

        $this->countInto($user, $poDetail, $location, 2);
        $this->scanInto($user, $poDetail, $location, $barcode);

        $rows = $this->historyFor($user, $location, $product)['receiving_history'];
        $this->assertCount(2, $rows);

        $counted = collect($rows)->firstWhere('scanned_barcode', null);
        $scanned = collect($rows)->firstWhere('scanned_barcode', $barcode);
        $this->assertNotNull($counted);
        $this->assertNotNull($scanned);

        $this->assertSame(2, $counted['generated_tag_count']);
        $this->assertSame('exact', $counted['tag_attribution']);

        $this->assertSame(1, $scanned['generated_tag_count']);
        $this->assertSame('exact', $scanned['tag_attribution']);

        $this->assertEmpty(
            array_intersect($counted['generated_tags'], $scanned['generated_tags']),
            'A tag must never be listed against two different stock-in lines.'
        );

        $all = array_merge($counted['generated_tags'], $scanned['generated_tags']);
        $this->assertCount(3, $all);
        $this->assertCount(3, array_unique($all), 'All three tags are distinct.');
    }

    /**
     * Two counted lines for the same product on one receiving cannot be told
     * apart by the data. The rows must say the tags are shared rather than
     * implying each line owns them.
     */
    public function test_two_counted_lines_on_one_receiving_report_shared_attribution(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->countInto($user, $poDetail, $location, 2);
        $this->countInto($user, $poDetail, $location, 3);

        $rows = $this->historyFor($user, $location, $product)['receiving_history'];
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame('shared', $row['tag_attribution']);
            $this->assertSame(2, $row['tag_line_count']);
            $this->assertSame(5, $row['generated_tag_count']);
        }
    }

    /** An appliance line keeps only its scanned serial — nothing is generated. */
    public function test_appliance_stock_in_row_reports_no_generated_tags(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type 1.5HP',
            'product_type' => 'appliance',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 5,
            'unit_price' => 100, 'subtotal' => 500,
        ]);
        $serial = 'SN-' . strtoupper(uniqid());

        $this->scanInto($user, $poDetail, $location, $serial);

        $rows = $this->historyFor($user, $location, $product)['receiving_history'];
        $this->assertCount(1, $rows);
        $this->assertSame($serial, $rows[0]['scanned_barcode']);
        $this->assertSame(0, $rows[0]['generated_tag_count']);
        $this->assertSame([], $rows[0]['generated_tags']);
    }

    /** The PO-group modal renders the same column and needs the same payload. */
    public function test_po_group_receiving_history_carries_generated_tags(): void
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();
        $product  = $this->makeMaterial('package');
        $poDetail = $this->makePoDetail($location, $product);

        $this->countInto($user, $poDetail, $location, 2);

        $rows = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $poDetail->po_id . '?location_id=' . $location->location_id)
            ->assertStatus(200)
            ->json('data.receiving_history');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['scanned_barcode']);
        $this->assertSame(2, $rows[0]['generated_tag_count']);
        $this->assertCount(2, $rows[0]['generated_tags']);
        $this->assertSame('exact', $rows[0]['tag_attribution']);
    }
}
