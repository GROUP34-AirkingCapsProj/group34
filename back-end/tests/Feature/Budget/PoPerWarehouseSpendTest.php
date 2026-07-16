<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestDetail;
use App\Models\Receiving;
use App\Models\ReceivingDetail;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PoPerWarehouseSpendTest extends BudgetTestCase
{
    private function warehouse(string $name): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH-' . uniqid(), 'location_name' => $name,
            'location_type' => 'warehouse', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], 'location_id');
    }

    private function product(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'P-' . uniqid(), 'product_name' => 'P',
            'product_type' => 'installation_material', 'unit_price' => 100, 'cost_price' => 80,
            'status_id' => 3, 'created_at' => now(), 'updated_at' => now(),
        ], 'product_id');
    }

    private function receiveWarehouse(PurchaseOrder $po, int $locationId): void
    {
        $lines = PurchaseOrderDetail::where('po_id', $po->po_id)
            ->where('location_id', $locationId)->get();
        $receiving = Receiving::create([
            'po_id' => $po->po_id,
            'location_id' => $locationId,
            'receiving_number' => 'RC-' . strtoupper(Str::random(6)),
            'receiving_date' => today(),
            'received_by' => $po->created_by,
        ]);
        foreach ($lines as $line) {
            ReceivingDetail::create([
                'receiving_id' => $receiving->receiving_id,
                'po_detail_id' => $line->po_detail_id,
                'product_id' => $line->product_id,
                'quantity_amount' => $line->quantity_ordered,
                'prod_price' => $line->unit_price,
                'condition' => 'Good',
            ]);
        }
    }

    public function test_each_warehouse_budget_settles_independently_as_it_is_received(): void
    {
        $whA = $this->warehouse('WH-A');
        $whB = $this->warehouse('WH-B');
        $budgetA = $this->makeBudget(10000, ['location_id' => $whA]);
        $budgetB = $this->makeBudget(10000, ['location_id' => $whB]);
        $pid = $this->product();

        $pr = PurchaseRequest::create([
            'location_id' => null, 'budget_id' => null,
            'pr_number' => 'PR-' . strtoupper(Str::random(6)),
            'request_date' => today(), 'needed_by_date' => today()->addDays(7),
            'estimated_total' => 500, 'status_id' => 14,
            'requested_by' => $this->makeUser('inventory_analyst')->user_id,
            'converted_to_po_id' => null,
        ]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whA,'quantity'=>2,'estimated_price'=>100,'subtotal'=>200]);
        PurchaseRequestDetail::create(['pr_id'=>$pr->pr_id,'product_id'=>$pid,'location_id'=>$whB,'quantity'=>3,'estimated_price'=>100,'subtotal'=>300]);

        $svc = app(BudgetService::class);

        $po = PurchaseOrder::create([
            'pr_id' => $pr->pr_id, 'supplier_id' => (int) DB::table('suppliers')->insertGetId(['supplier_name'=>'S','created_at'=>now(),'updated_at'=>now()], 'supplier_id'),
            'location_id' => $whA, 'po_number' => 'PO-' . strtoupper(Str::random(6)),
            'order_date' => today(), 'total_amount' => 500, 'grand_total' => 500,
            'status_id' => 7, 'created_by' => $pr->requested_by,
        ]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whA,'product_id'=>$pid,'quantity_ordered'=>2,'unit_price'=>100,'subtotal'=>200]);
        PurchaseOrderDetail::create(['po_id'=>$po->po_id,'location_id'=>$whB,'product_id'=>$pid,'quantity_ordered'=>3,'unit_price'=>100,'subtotal'=>300]);

        // PO approval reserves against each warehouse's budget.
        $svc->commitPo($po->fresh()->load('details'), null);

        $this->receiveWarehouse($po, $whA);
        $svc->spend($po->fresh(), null);

        $this->assertSame(200.0, (float) $budgetA->fresh()->spent, 'A settled its 200');
        $this->assertSame(0.0,   (float) $budgetB->fresh()->spent, 'B not yet received');
        $this->assertSame(0.0,   (float) $budgetA->fresh()->committed, 'A commitment released');
        $this->assertSame(300.0, (float) $budgetB->fresh()->committed, 'B still committed');

        $this->receiveWarehouse($po, $whB);
        $svc->spend($po->fresh(), null);

        $this->assertSame(200.0, (float) $budgetA->fresh()->spent, 'A not double-spent');
        $this->assertSame(300.0, (float) $budgetB->fresh()->spent, 'B now settled');
        $this->assertSame(0.0,   (float) $budgetB->fresh()->committed, 'B commitment released');

        $this->assertSame(1, BudgetTransaction::where('po_id',$po->po_id)->where('budget_id',$budgetA->budget_id)->where('txn_type',BudgetTransaction::TYPE_SPEND)->count());
        $this->assertSame(1, BudgetTransaction::where('po_id',$po->po_id)->where('budget_id',$budgetB->budget_id)->where('txn_type',BudgetTransaction::TYPE_SPEND)->count());
    }
}
