<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehousePrApproveTest extends BudgetTestCase
{
    private function warehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => 'WH',
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    private function twoWarehousePr(int $whAId, int $whBId): PurchaseRequest
    {
        $pr = PurchaseRequest::create([
            'location_id' => null, 'supplier_id' => null, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 13,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
        ]);
        $pid = (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whAId,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whBId,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);
        return $pr;
    }

    public function test_approve_validates_each_warehouse_but_reserves_nothing(): void
    {
        $admin = $this->makeUser('admin');
        $whA = $this->locationId; $whB = $this->warehouse();
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB]);
        $pr = $this->twoWarehousePr($whA, $whB);

        $this->actingAs($admin, 'sanctum')->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();

        // PR approval validates availability only — reservation moved to PO approval.
        $this->assertSame('0.00', (string) $bA->fresh()->committed);
        $this->assertSame('0.00', (string) $bB->fresh()->committed);
        $this->assertSame(14, (int) $pr->fresh()->status_id);
    }

    public function test_po_approval_commits_each_warehouse_to_its_budget(): void
    {
        $admin = $this->makeUser('admin');
        $whA = $this->locationId; $whB = $this->warehouse();
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB]);
        $pr = $this->twoWarehousePr($whA, $whB);
        $pr->update(['status_id' => 14]);

        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'S', 'created_at' => now(), 'updated_at' => now(),
        ], 'supplier_id');
        $po = PurchaseOrder::create([
            'pr_id' => $pr->pr_id, 'supplier_id' => $supplierId, 'location_id' => $whA,
            'po_number' => 'PO-' . strtoupper(Str::random(6)), 'order_date' => today(),
            'total_amount' => 500, 'grand_total' => 500, 'status_id' => 7,
            'created_by' => $pr->requested_by,
        ]);
        $pid = (int) DB::table('products')->value('product_id');
        DB::table('purchase_order_details')->insert([
            ['po_id' => $po->po_id, 'location_id' => $whA, 'product_id' => $pid, 'quantity_ordered' => 2, 'unit_price' => 100, 'subtotal' => 200, 'created_at' => now(), 'updated_at' => now()],
            ['po_id' => $po->po_id, 'location_id' => $whB, 'product_id' => $pid, 'quantity_ordered' => 3, 'unit_price' => 100, 'subtotal' => 300, 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(BudgetService::class)->commitPo($po->fresh()->load('details'), $admin->user_id);

        $this->assertSame('200.00', (string) $bA->fresh()->committed);
        $this->assertSame('300.00', (string) $bB->fresh()->committed);
    }

    public function test_approve_all_or_nothing_when_one_warehouse_unverified(): void
    {
        $admin = $this->makeUser('admin');
        $whA = $this->locationId; $whB = $this->warehouse();
        $bA = $this->makeBudget(10000, ['location_id' => $whA]);
        $bB = $this->makeBudget(10000, ['location_id' => $whB, 'verification_status' => 'pending']);
        $pr = $this->twoWarehousePr($whA, $whB);

        $res = $this->actingAs($admin, 'sanctum')->patchJson("/api/purchase-requests/{$pr->pr_id}/approve");

        $res->assertStatus(422);
        $this->assertSame(13, (int) $pr->fresh()->status_id);
        $this->assertSame('0.00', (string) $bA->fresh()->committed);
        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->count());
    }
}
