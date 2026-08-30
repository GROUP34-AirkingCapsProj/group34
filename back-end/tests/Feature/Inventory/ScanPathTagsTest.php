<?php

namespace Tests\Feature\Inventory;

use App\Models\ConsumableDetail;
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

class ScanPathTagsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_scanned_supplier_barcode_gets_one_tag_carrying_both_identifiers(): void
    {
        $location = Location::create([
            'location_code' => 'LOC-' . uniqid(), 'location_name' => 'Test Loc', 'location_type' => 'warehouse',
        ]);
        $product = Product::create([
            'product_code' => 'CON-' . uniqid(), 'product_name' => 'Bolt M8',
            'product_type' => 'installation_material',
        ]);
        ConsumableDetail::create([
            'product_id' => $product->product_id, 'pieces_per_package' => 100,
            'package_unit' => 'Box', 'is_serialized' => 0, 'count_unit' => 'piece',
        ]);
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
            'product_id' => $product->product_id, 'quantity_ordered' => 10,
            'unit_price' => 5, 'subtotal' => 50,
        ]);

        $barcode = 'SUP-' . strtoupper(uniqid());

        $res = $this->actingAs($user)->postJson('/api/inventory/scan-transaction', [
            'barcode'          => $barcode,
            'transaction_type' => 'receiving',
            'location_id'      => $location->location_id,
            'po_id'            => $po->po_id,
            'po_detail_id'     => $poDetail->po_detail_id,
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.action', 'applied');

        $batch = InventoryBatch::where('product_id', $product->product_id)
            ->where('location_id', $location->location_id)
            ->first();
        $this->assertNotNull($batch, 'Expected a batch for the scanned receipt.');

        $tags = ItemSerial::where('batch_id', $batch->batch_id)->get();
        $this->assertCount(1, $tags, 'One scan of a count_unit=piece product must mint exactly one tag.');

        $tag = $tags->first();
        $this->assertSame($barcode, $tag->supplier_barcode);
        $this->assertStringStartsWith('INT-', (string) $tag->serial_number);

        $entryMode = DB::table('receiving_details')
            ->where('receiving_id', $batch->receiving_id)
            ->where('product_id', $product->product_id)
            ->value('entry_mode');
        $this->assertSame('scanned', $entryMode);
    }
}
