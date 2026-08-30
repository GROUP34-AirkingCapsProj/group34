<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\User;
use App\Services\ReceivingPostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An appliance must never enter stock without a barcode.
 *
 * Count-based receiving exists for installation materials only — copper
 * tubing, foam, bolts — where a delivery genuinely arrives unlabelled and the
 * warehouse counts it by hand. An appliance is different: warranty lookup,
 * incident reports, transfers and issuance all resolve a unit by its exact
 * serial, so an appliance received without one is untraceable for the rest of
 * its life and cannot be claimed against.
 *
 * These tests pin that rule at every entry point, so a future change to any
 * one path cannot quietly open it.
 */
class ApplianceAlwaysNeedsBarcodeTest extends TestCase
{
    use DatabaseTransactions;

    private function scaffold(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);

        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 'tester_' . uniqid(), 'first_name' => 'Test', 'last_name' => 'User',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 5,
            'unit_price' => 1, 'subtotal' => 5,
        ]);

        return [$location, Product::with('applianceDetail')->find($product->product_id), $user, $poDetail];
    }

    public function test_the_count_endpoint_refuses_an_appliance_outright(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold();

        $res = $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'rejected');

        $this->assertNull(
            Inventory::where('product_id', $product->product_id)->first(),
            'Counting must never move appliance stock — the guard has to fire before any write.'
        );
        $this->assertSame(0, ItemSerial::where('product_id', $product->product_id)->count());
    }

    public function test_a_blank_barcode_is_refused_on_the_scan_endpoint(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold();

        // Laravel's `required` rule trims a string before testing emptiness, so
        // whitespace is refused. Pinned here because the appliance rule would
        // otherwise rest on that behaviour without anything asserting it.
        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => '   ',
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ]);

        $res->assertStatus(422);

        $this->assertNull(Inventory::where('product_id', $product->product_id)->first());
        $this->assertSame(0, ItemSerial::where('product_id', $product->product_id)->count());
    }

    public function test_the_posting_service_refuses_a_serialized_product_with_no_serial(): void
    {
        [$location, $product, , $poDetail] = $this->scaffold();

        $batch = InventoryBatch::create([
            'product_id'         => $product->product_id,
            'location_id'        => $location->location_id,
            'quantity_received'  => 0,
            'quantity_remaining' => 0,
            'received_at'        => now(),
        ]);

        $this->expectException(\RuntimeException::class);

        // Calling the service directly, bypassing both controllers' guards, to
        // prove the invariant is owned by the code that mints serials rather
        // than only by a validation rule upstream.
        DB::transaction(fn () => app(ReceivingPostingService::class)->post(
            product: $product,
            locationId: (int) $location->location_id,
            pieces: 1,
            entryMode: 'scanned',
            poDetail: $poDetail,
            supplierBarcode: null,
        ));
    }

    public function test_a_scanned_appliance_keeps_its_serial_verbatim(): void
    {
        [$location, $product, $user, $poDetail] = $this->scaffold();
        $serial = 'SN-' . strtoupper(uniqid());

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $serial,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $poDetail->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $row = ItemSerial::where('product_id', $product->product_id)->first();

        $this->assertNotNull($row, 'A scanned appliance must always produce a serial record.');
        $this->assertSame($serial, $row->serial_number, 'The manufacturer serial must be stored verbatim.');
        $this->assertStringStartsNotWith(
            'INT-',
            $row->serial_number,
            'An appliance must never be given a generated INT- tag — warranty lookup resolves units by the real serial.'
        );
    }
}
