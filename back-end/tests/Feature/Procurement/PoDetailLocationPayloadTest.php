<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PoDetailLocationPayloadTest extends ProcurementTestCase
{
    private function warehouse(string $name, array $extra = []): int
    {
        return (int) DB::table('locations')->insertGetId(array_merge([
            'location_code' => 'WH-' . uniqid(),
            'location_name' => $name,
            'location_type' => 'warehouse',
            'address'       => '123 ' . $name . ' St',
            'city'          => 'Cebu City',
            'province'      => 'Cebu',
            'region'        => 'Region VII',
            'contact_number'=> '032-000-1111',
            'email'         => strtolower($name) . '@wh.local',
            'manager_name'  => 'Mgr ' . $name,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $extra), 'location_id');
    }

    private function product(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }

    public function test_show_endpoint_returns_full_location_per_detail_line(): void
    {
        $whA = $this->warehouse('Alpha');
        $whB = $this->warehouse('Bravo');
        $pid = $this->product();
        $supplierId = (int) DB::table('suppliers')->insertGetId(
            ['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id'
        );
        $admin = $this->makeUser('admin');

        $po = PurchaseOrder::create([
            'supplier_id' => $supplierId, 'location_id' => $whA,
            'po_number' => 'PO-' . strtoupper(Str::random(6)),
            'order_date' => today(), 'total_amount' => 500, 'grand_total' => 500,
            'status_id' => 7, 'created_by' => $admin->user_id,
        ]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whA,'product_id'=>$pid,'quantity_ordered'=>2,'unit_price'=>100,'subtotal'=>200]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whB,'product_id'=>$pid,'quantity_ordered'=>3,'unit_price'=>100,'subtotal'=>300]);

        $resp = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/purchase-orders/{$po->po_id}")
            ->assertOk();

        $details = collect($resp->json('data.details'));
        $byLoc = $details->keyBy(fn ($d) => $d['location_id']);

        $this->assertNotNull($byLoc[$whA]['location'], 'line A carries a nested location');
        $this->assertSame('123 Alpha St', $byLoc[$whA]['location']['address']);
        $this->assertSame('Cebu City',    $byLoc[$whA]['location']['city']);
        $this->assertSame('Mgr Bravo',    $byLoc[$whB]['location']['manager_name']);
        $this->assertSame('123 Bravo St', $byLoc[$whB]['location']['address']);
    }
}
