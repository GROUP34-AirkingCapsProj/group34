<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A box marked 50 that only holds 47 used to post 50 and the shortfall
 * vanished. `packages_received` and `pieces_expected` now survive on the
 * receiving_details row; this pins that both Stock-In History surfaces (the
 * per-SKU detail modal via InventoryController, and the PO-group modal via
 * PoInventoryController) actually expose them, so the frontend has something
 * to compare against `quantity_amount`.
 *
 * A second, unrelated regression is pinned here too: a scanned material's
 * stock-in row must still expose its supplier code through the field
 * AttachesGeneratedTags populates, so the two halves (the trait's grouping
 * and the row it decorates) cannot silently drift apart.
 */
class StockInShortfallHistoryTest extends TestCase
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

    private function scaffold(): array
    {
        $user     = $this->makeUser();
        $location = $this->makeLocation();

        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 500,
            'unit_price' => 5, 'subtotal' => 2500,
        ]);

        return [$location, $product, $user, $po, $poDetail];
    }

    public function test_a_shortfall_receipt_exposes_the_expectation_on_the_per_sku_history(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffold();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $poDetail->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 6,
            'actual_pieces' => 297,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();

        $rows = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.receiving_history');

        $this->assertCount(1, $rows);
        $this->assertSame(297, $rows[0]['quantity_amount']);
        $this->assertSame(6, $rows[0]['packages_received']);
        $this->assertSame(300, $rows[0]['pieces_expected']);
    }

    public function test_a_shortfall_receipt_exposes_the_expectation_on_the_po_group_history(): void
    {
        [$location, , $user, $po, $poDetail] = $this->scaffold();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $poDetail->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 6,
            'actual_pieces' => 297,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rows = $this->actingAs($user)
            ->getJson('/api/po-inventory-groups/' . $po->po_id . '?location_id=' . $location->location_id)
            ->assertStatus(200)
            ->json('data.receiving_history');

        $this->assertCount(1, $rows);
        $this->assertSame(297, $rows[0]['quantity_amount']);
        $this->assertSame(6, $rows[0]['packages_received']);
        $this->assertSame(300, $rows[0]['pieces_expected']);
    }

    /** A clean receipt (actual matches expected) still carries the columns — there is just nothing to flag. */
    public function test_a_clean_receipt_still_carries_matching_columns(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffold();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 6,
        ])->assertOk();

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();

        $rows = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.receiving_history');

        $this->assertSame(300, $rows[0]['quantity_amount']);
        $this->assertSame(300, $rows[0]['pieces_expected']);
    }

    /**
     * mintForScan no longer generates an INT- serial for a barcode that
     * arrived with the goods; it keeps the supplier's own code as the tag.
     * AttachesGeneratedTags must still surface that code on the scanned
     * line's stock-in row through `generated_tags`, the same field a counted
     * line's minted tags travel through — the UI reads that field regardless
     * of which case produced it.
     */
    public function test_a_scanned_lines_stock_in_row_exposes_the_supplier_code(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffold();
        $code = 'SUP-' . strtoupper(uniqid());

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $code,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $poDetail->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $inventory = Inventory::where('location_id', $location->location_id)
            ->where('product_id', $product->product_id)
            ->firstOrFail();

        $rows = $this->actingAs($user)
            ->getJson('/api/inventory/' . $inventory->inventory_id . '/history')
            ->assertStatus(200)
            ->json('data.receiving_history');

        $this->assertCount(1, $rows);
        $this->assertSame($code, $rows[0]['scanned_barcode']);
        $this->assertSame(1, $rows[0]['generated_tag_count']);
        $this->assertSame([$code], $rows[0]['generated_tags'],
            'The scanned line\'s own supplier code must be the value AttachesGeneratedTags surfaces for it.');
        $this->assertSame('exact', $rows[0]['tag_attribution']);
    }
}
