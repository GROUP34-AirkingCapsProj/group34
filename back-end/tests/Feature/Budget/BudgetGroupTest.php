<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BudgetGroupTest extends BudgetTestCase
{
    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetService::class);
    }

    private function secondWarehouse(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'WH2-' . uniqid(),
            'location_name' => 'Warehouse Two',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function newShapePr(float $estimatedTotal): PurchaseRequest
    {
        return PurchaseRequest::create([
            'location_id'     => null,
            'supplier_id'     => null,
            'budget_id'       => null,
            'pr_number'       => 'PR-G-' . strtoupper(Str::random(6)),
            'request_date'    => today(),
            'needed_by_date'  => today()->addDays(7),
            'estimated_total' => $estimatedTotal,
            'status_id'       => 14,
            'requested_by'    => $this->makeUser('inventory_analyst')->user_id,
        ]);
    }

    private function anyProductId(): int
    {
        return (int) (DB::table('products')->value('product_id')
            ?? DB::table('products')->insertGetId([
                'product_name' => 'Budget Test Product ' . uniqid(),
                'product_code' => 'BTP-' . strtoupper(substr(uniqid(), -6)),
                'product_type' => 'consumable',
                'created_at'   => now(),
                'updated_at'   => now(),
            ], 'product_id'));
    }

    /** Build a multi-warehouse PO: one line per (locationId, subtotal) pair. */
    private function multiWarehousePo(PurchaseRequest $pr, int $primaryLocationId, array $lines): PurchaseOrder
    {
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Budget Group Supplier ' . uniqid(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'supplier_id');

        $total = array_sum(array_column($lines, 1));

        $po = PurchaseOrder::create([
            'pr_id'        => $pr->pr_id,
            'supplier_id'  => $supplierId,
            'location_id'  => $primaryLocationId,
            'po_number'    => 'PO-G-' . strtoupper(Str::random(6)),
            'order_date'   => today(),
            'total_amount' => $total,
            'grand_total'  => $total,
            'status_id'    => 7,
            'created_by'   => $pr->requested_by,
        ]);

        foreach ($lines as [$locationId, $subtotal]) {
            DB::table('purchase_order_details')->insert([
                'po_id'            => $po->po_id,
                'product_id'       => $this->anyProductId(),
                'quantity_ordered' => 1,
                'unit_price'       => $subtotal,
                'subtotal'         => $subtotal,
                'location_id'      => $locationId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        return $po;
    }

    public function test_commit_po_writes_ledger_row_per_budget(): void
    {
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);
        $po = $this->multiWarehousePo($pr, $whA->location_id, [
            [$whA->location_id, 4000],
            [$whBId, 2500],
        ]);

        $this->service->commitPo($po->fresh()->load('details'));

        $whA->refresh(); $whB->refresh();
        $this->assertSame('4000.00', (string) $whA->committed);
        $this->assertSame('2500.00', (string) $whB->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('budget_id', $whA->budget_id)->where('txn_type', 'commit')->count());
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('budget_id', $whB->budget_id)->where('txn_type', 'commit')->count());
    }

    public function test_commit_po_idempotent_per_budget_and_po(): void
    {
        $whA = $this->makeBudget(10000);
        $pr = $this->newShapePr(4000);
        $po = $this->multiWarehousePo($pr, $whA->location_id, [
            [$whA->location_id, 4000],
        ]);

        $this->service->commitPo($po->fresh()->load('details'));
        $this->service->commitPo($po->fresh()->load('details'));

        $this->assertSame('4000.00', (string) $whA->fresh()->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('budget_id', $whA->budget_id)->where('txn_type', 'commit')->count());
    }

    public function test_release_commitment_returns_each_budget_share(): void
    {
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);
        $po = $this->multiWarehousePo($pr, $whA->location_id, [
            [$whA->location_id, 4000],
            [$whBId, 2500],
        ]);
        $this->service->commitPo($po->fresh()->load('details'));

        $released = $this->service->releaseCommitment($po->fresh(), 'PO rejected');

        $this->assertSame(6500.0, $released);
        $this->assertSame('0.00', (string) $whA->fresh()->committed);
        $this->assertSame('0.00', (string) $whB->fresh()->committed);
    }

    public function test_spend_resolves_budget_by_po_location(): void
    {
        // A separate PO scoped entirely to warehouse B must settle against B's
        // budget only, even though a sibling budget exists for warehouse A.
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(2500);
        $po = $this->multiWarehousePo($pr, $whBId, [
            [$whBId, 2500],
        ]);
        $this->service->commitPo($po->fresh()->load('details'));

        $po->update(['status_id' => 11]);

        $this->service->spend($po->fresh()->load('details'), null);

        $whA->refresh(); $whB->refresh();
        $this->assertSame('0.00', (string) $whA->spent);
        $this->assertSame('2500.00', (string) $whB->spent);
        $this->assertSame('0.00', (string) $whA->committed);
        $this->assertSame('0.00', (string) $whB->committed);
    }
}
