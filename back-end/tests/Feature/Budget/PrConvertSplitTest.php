<?php

namespace Tests\Feature\Budget;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrConvertSplitTest extends BudgetTestCase
{
    private function warehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    public function test_convert_creates_one_po_with_per_line_warehouses(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId; $whB = $this->warehouse();
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');

        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'approved_by' => $admin->user_id, 'approval_date' => today(),
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ]);

        $res->assertSuccessful();
        $pos = PurchaseOrder::where('pr_id', $pr->pr_id)->get();
        $this->assertCount(1, $pos, 'conversion must create exactly one PO');
        $po = $pos->first();
        $this->assertSame($supplier, (int) $po->supplier_id);
        $this->assertSame(7, (int) $po->status_id);
        $this->assertSame(500.0, (float) $po->total_amount);

        $lineLocs = \App\Models\PurchaseOrderDetail::where('po_id', $po->po_id)
            ->pluck('location_id')->map(fn ($v) => (int) $v)->all();
        $this->assertEqualsCanonicalizing([$whA, $whB], $lineLocs);
        $this->assertContains((int) $po->location_id, [$whA, $whB]);
    }

    public function test_reconvert_blocked(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $wh = $this->locationId;
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 200, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'converted_to_po_id' => null,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$wh,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);

        $body = ['details' => [['product_id' => $pid, 'location_id' => $wh, 'quantity_ordered' => 2, 'unit_price' => 100]]];
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", $body)->assertSuccessful();
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", $body)->assertStatus(422);
    }

    public function test_convert_rejects_line_for_warehouse_not_on_pr(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId;
        $whOther = $this->warehouse(); // a warehouse NOT on the PR
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 200, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);

        // Convert but point the line at $whOther (not on the PR).
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [['product_id' => $pid, 'location_id' => $whOther, 'quantity_ordered' => 2, 'unit_price' => 100]],
        ])->assertStatus(422);

        $this->assertSame(0, PurchaseOrder::where('pr_id', $pr->pr_id)->count());
    }

    public function test_po_show_returns_per_line_warehouse(): void
    {
        $admin = $this->makeUser('admin');
        $supplier = (int) DB::table('suppliers')->insertGetId(['supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now()], 'supplier_id');
        $whA = $this->locationId; $whB = $this->warehouse();
        $this->makeBudget(10000, ['location_id' => $whA]);
        $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => $supplier, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/purchase-requests/{$pr->pr_id}/convert", [
            'details' => [
                ['product_id' => $pid, 'location_id' => $whA, 'quantity_ordered' => 2, 'unit_price' => 100],
                ['product_id' => $pid, 'location_id' => $whB, 'quantity_ordered' => 3, 'unit_price' => 100],
            ],
        ])->assertSuccessful();
        $poId = (int) PurchaseOrder::where('pr_id', $pr->pr_id)->value('po_id');

        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/purchase-orders/{$poId}");
        $res->assertSuccessful();
        $locs = collect($res->json('data.details'))->pluck('location_id')->map(fn ($v) => (int) $v)->all();
        $this->assertEqualsCanonicalizing([$whA, $whB], $locs);
        $this->assertNotNull($res->json('data.details.0.location_name'));
    }
}
