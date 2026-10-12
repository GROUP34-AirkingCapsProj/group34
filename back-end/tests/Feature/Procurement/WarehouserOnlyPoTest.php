<?php

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;

class WarehouserOnlyPoTest extends ProcurementTestCase
{
    public function test_direct_po_create_to_showroom_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $showroom = (int) DB::table('locations')->insertGetId([
            'location_code' => 'SR-' . uniqid(), 'location_name' => 'Showroom',
            'location_type' => 'showroom', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
        $supplier = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier, 'location_id' => $showroom,
            'po_number' => 'PO-' . uniqid(), 'order_date' => today()->toDateString(),
            'details' => [['product_id' => $pid, 'quantity_ordered' => 1, 'unit_price' => 100]],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('warehouse', strtolower($res->json('message')));
    }

    public function test_direct_po_create_to_warehouse_is_allowed(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');

        // $this->locationId is a warehouse.
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier, 'location_id' => $this->locationId,
            'po_number' => 'PO-' . uniqid(), 'order_date' => today()->toDateString(),
            'details' => [['product_id' => $pid, 'quantity_ordered' => 1, 'unit_price' => 100]],
        ]);

        $res->assertStatus(201);
    }
}
