<?php

namespace Tests\Feature\Inventory;

use App\Models\ApplianceDetail;
use App\Models\ConsumableDetail;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\ReceivingDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Package progress must be counted, never derived from pieces.
 *
 * Piece verification means a package can legitimately post fewer pieces than
 * its nominal size, so pieces / pieces_per_package floors a genuine 2 packs
 * down to 1. Every receiving row records the package count it represents.
 */
class PackageProgressTest extends TestCase
{
    use DatabaseTransactions;

    private function user(): User
    {
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);

        return User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
    }

    /** A consumable line, 82 pieces per package, 3 packages ordered. */
    private function materialScaffold(int $ppp = 82, int $ordered = 3): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Copper Tube',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => $ppp,
            'package_unit' => 'Pack', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => $ordered,
            'unit_price' => 1, 'subtotal' => $ordered,
        ]);

        return [$location, $product, $this->user(), $po, $poDetail];
    }

    private function applianceScaffold(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'APP-' . uniqid(), 'product_name' => 'Split Type AC',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create(['product_id' => $product->product_id, 'is_serialized' => 1]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 5,
            'unit_price' => 1, 'subtotal' => 5,
        ]);

        return [$location, $product, $this->user(), $po, $poDetail];
    }

    public function test_a_scanned_material_row_records_one_package_and_its_expected_pieces(): void
    {
        [$location, , $user, $po, $poDetail] = $this->materialScaffold();

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SUP-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $row = ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)->firstOrFail();

        $this->assertSame(1, (int) $row->packages_received,
            'One scan is exactly one package — the row must say so rather than leaving it to division.');
        $this->assertSame(82, (int) $row->pieces_expected,
            'The nominal contents of the scanned package, so a short count is measurable later.');
    }

    public function test_an_appliance_scan_records_no_package_figures(): void
    {
        [$location, , $user, $po, $poDetail] = $this->applianceScaffold();

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SN-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $row = ReceivingDetail::where('po_detail_id', $poDetail->po_detail_id)->firstOrFail();

        $this->assertNull($row->packages_received, 'An appliance has no package concept.');
        $this->assertNull($row->pieces_expected, 'An appliance has no package contents to expect.');
    }

    /**
     * The reported bug, end to end: 2 packs counted 14 pieces short plus 1 pack
     * scanned. The line is 3 packages received, not the 1 that
     * floor(150 / 82) used to report.
     */
    public function test_po_scan_status_sums_packages_across_a_short_counted_and_a_scanned_row(): void
    {
        [$location, , $user, $po, $poDetail] = $this->materialScaffold();

        // 2 packs entered by hand, but the boxes held 150 pieces, not 164.
        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id'  => $poDetail->po_detail_id,
            'location_id'   => $location->location_id,
            'quantity'      => 2,
            'actual_pieces' => 150,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        // 1 pack that arrived with a supplier barcode.
        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => 'SUP-' . strtoupper(uniqid()),
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $res = $this->actingAs($user)
            ->getJson('/api/receivings/po-scan-status/' . $po->po_id)
            ->assertOk();

        $line = collect($res->json('data.lines'))
            ->firstWhere('po_detail_id', $poDetail->po_detail_id);

        $this->assertNotNull($line, 'The PO line must appear in the scan status payload.');
        $this->assertSame(232, (int) $line['already_received'],
            '150 counted pieces plus one 82-piece package.');
        $this->assertSame(3, (int) $line['already_received_packages'],
            'Three packages arrived. Dividing 232 pieces by 82 would say 2 — packages must be summed, not derived.');
    }
}
