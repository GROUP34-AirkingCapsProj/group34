<?php

namespace Tests\Feature\Procurement;

use App\Models\InstallationMaterialDetail;
use App\Models\Product;
use App\Models\Receiving;
use Illuminate\Support\Facades\DB;

class PoLifecyclePayloadTest extends ProcurementTestCase
{
    public function test_po_detail_exposes_snapshot_and_receipt_balances(): void
    {
        $admin = $this->makeUser('admin');
        $po = $this->makePo($this->makePr(100), 100, 8);
        $product = Product::create([
            'product_code' => 'LIFE-'.uniqid(), 'product_name' => 'Material', 'product_type' => 'installation_material',
        ]);
        InstallationMaterialDetail::create(['product_id' => $product->product_id, 'pieces_per_package' => 10]);
        $line = $po->details()->create([
            'product_id' => $product->product_id, 'location_id' => $this->locationId,
            'quantity_ordered' => 2, 'unit_price' => '50.00', 'subtotal' => '100.00',
        ]);
        $receipt = Receiving::create([
            'po_id' => $po->po_id, 'location_id' => $this->locationId,
            'receiving_number' => 'LIFE-'.uniqid(), 'receiving_date' => today(),
        ]);
        DB::table('receiving_details')->insert([
            'receiving_id' => $receipt->receiving_id, 'po_detail_id' => $line->po_detail_id,
            'product_id' => $product->product_id, 'quantity_amount' => 7, 'prod_price' => '5.00',
        ]);
        $this->actingAs($admin)->getJson('/api/purchase-orders/'.$po->po_id)
            ->assertOk()->assertJsonPath('data.lifecycle_status', 'partially_received')
            ->assertJsonPath('data.lifecycle_label', 'Partially Received')
            ->assertJsonPath('data.details.0.order_unit', 'package')
            ->assertJsonPath('data.details.0.pieces_per_order_unit', 10)
            ->assertJsonPath('data.details.0.quantity_balance.received', 7)
            ->assertJsonPath('data.details.0.quantity_balance.remaining', 13);
    }
}
