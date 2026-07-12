<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class WarehouseFlowE2ETest extends BudgetTestCase
{
    public function test_two_warehouse_pr_settles_against_each_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $admin = $this->makeUser('admin');
        $whA = $this->locationId;
        $whB = (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH B',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB]);
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        DB::table('supplier_product')->insert(['supplier_id' => $supplier, 'product_id' => $pid, 'product_price' => 80, 'created_at' => now(), 'updated_at' => now()]);

        // Submit
        $prId = $this->actingAs($ia, 'sanctum')->postJson('/api/purchase-requests', [
            'supplier_id' => $supplier, 'needed_by_date' => today()->addDays(7)->toDateString(),
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity' => 2, 'estimated_price' => 100], // 200
                ['product_id' => $pid, 'location_id' => $whB, 'quantity' => 3, 'estimated_price' => 100], // 300
            ],
        ])->assertStatus(201)->json('data.pr_id');

        // Approve → commits 200 to A, 300 to B
        $this->actingAs($admin, 'sanctum')->patchJson("/api/purchase-requests/{$prId}/approve")->assertSuccessful();
        $this->assertSame('200.00', (string) $bA->fresh()->committed);
        $this->assertSame('300.00', (string) $bB->fresh()->committed);

        // Convert → ONE PO with per-line warehouses
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$prId}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ])->assertSuccessful();
        $pos = PurchaseOrder::where('pr_id', $prId)->get();
        $this->assertCount(1, $pos);
        $po = $pos->first();
        $po->update(['verification_status' => 'verified']); // auditor gate for receiving

        // Receive warehouse A's lines → settles A only; PO Partially Received.
        $aLines = \App\Models\PurchaseOrderDetail::where('po_id', $po->po_id)->where('location_id', $whA)->get();
        $this->actingAs($admin, 'sanctum')->postJson('/api/receivings', [
            'pc_id' => $po->po_id, 'location_id' => $whA,
            'receiving_number' => 'RC-' . strtoupper(\Illuminate\Support\Str::random(6)),
            'receiving_date' => today()->toDateString(),
            'details' => $aLines->map(fn ($l) => ['po_detail_id' => $l->po_detail_id, 'product_id' => $l->product_id, 'quantity_amount' => $l->quantity_ordered])->all(),
        ])->assertSuccessful();

        $this->assertSame('200.00', (string) $bA->fresh()->spent);
        $this->assertSame('0.00', (string) $bB->fresh()->spent);
        $this->assertSame('0.00', (string) $bA->fresh()->committed);
        $this->assertSame('300.00', (string) $bB->fresh()->committed);
        $this->assertSame(12, (int) $po->fresh()->status_id);

        // Receive warehouse B's lines → settles B; PO Completed.
        $bLines = \App\Models\PurchaseOrderDetail::where('po_id', $po->po_id)->where('location_id', $whB)->get();
        $this->actingAs($admin, 'sanctum')->postJson('/api/receivings', [
            'pc_id' => $po->po_id, 'location_id' => $whB,
            'receiving_number' => 'RC-' . strtoupper(\Illuminate\Support\Str::random(6)),
            'receiving_date' => today()->toDateString(),
            'details' => $bLines->map(fn ($l) => ['po_detail_id' => $l->po_detail_id, 'product_id' => $l->product_id, 'quantity_amount' => $l->quantity_ordered])->all(),
        ])->assertSuccessful();

        $this->assertSame('200.00', (string) $bA->fresh()->spent);
        $this->assertSame('300.00', (string) $bB->fresh()->spent);
        $this->assertSame('0.00', (string) $bB->fresh()->committed);
        $this->assertSame(11, (int) $po->fresh()->status_id);
    }
}
