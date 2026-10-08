<?php

namespace Tests\Feature\Inventory;

use App\Models\InstallationMaterialDetail;
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
 * A generated barcode exists only because an item arrived without one. If the
 * clerk scanned a supplier code, that code is the identifier and inventing a
 * second one is noise.
 */
class ScannedMaterialKeepsSupplierCodeTest extends TestCase
{
    use DatabaseTransactions;

    private function scaffold(): array
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'L', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'PE Foam',
            'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 50,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'package',
        ]);
        $roleId = (int) (DB::table('roles')->min('role_id') ?? 1);
        $user = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@e.test', 'password_hash' => bcrypt('x'), 'role_id' => $roleId,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'location_id' => $location->location_id,
            'verification_status' => 'verified', 'status_id' => 8,
        ]);
        $poDetail = PurchaseOrderDetail::create([
            'po_id' => $po->po_id, 'location_id' => $location->location_id,
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 1, 'subtotal' => 10,
        ]);

        return [$location, $product, $user, $po, $poDetail];
    }

    public function test_a_scanned_material_keeps_the_supplier_code_as_its_serial(): void
    {
        [$location, $product, $user, $po, $poDetail] = $this->scaffold();
        $code = 'SUP-' . strtoupper(uniqid());

        $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $code,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rows = ItemSerial::where('product_id', $product->product_id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($code, $rows[0]->serial_number,
            'The supplier code is the identifier — no INT- serial should be generated for it.');
        $this->assertSame($code, $rows[0]->supplier_barcode,
            'supplier_barcode stays populated so "came from the supplier" is testable without prefix matching.');
        $this->assertNotNull($rows[0]->batch_id, 'The batch link must survive, or FIFO cannot verify the pick.');
    }

    public function test_a_counted_material_still_gets_generated_tags(): void
    {
        [$location, $product, $user, , $poDetail] = $this->scaffold();

        $this->actingAs($user)->postJson('/api/inventory/receive-count', [
            'po_detail_id' => $poDetail->po_detail_id,
            'location_id'  => $location->location_id,
            'quantity'     => 2,
        ])->assertOk()->assertJsonPath('data.action', 'applied');

        $rows = ItemSerial::where('product_id', $product->product_id)->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('INT-', $row->serial_number);
            $this->assertNull($row->supplier_barcode);
        }
    }
}
