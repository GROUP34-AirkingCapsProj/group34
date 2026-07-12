<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
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
            'status_id'       => 13,
            'requested_by'    => $this->makeUser('inventory_analyst')->user_id,
        ]);
    }

    public function test_commit_group_writes_ledger_row_per_budget(): void
    {
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);

        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whB, 2500, null);

        $whA->refresh(); $whB->refresh();
        $this->assertSame('4000.00', (string) $whA->committed);
        $this->assertSame('2500.00', (string) $whB->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('budget_id', $whA->budget_id)->where('txn_type', 'commit')->count());
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('budget_id', $whB->budget_id)->where('txn_type', 'commit')->count());
    }

    public function test_commit_group_idempotent_per_budget_and_pr(): void
    {
        $whA = $this->makeBudget(10000);
        $pr = $this->newShapePr(4000);

        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whA, 4000, null);

        $this->assertSame('4000.00', (string) $whA->fresh()->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('budget_id', $whA->budget_id)->where('txn_type', 'commit')->count());
    }

    public function test_release_outstanding_returns_each_budget_share(): void
    {
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);
        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whB, 2500, null);

        $released = $this->service->releaseOutstanding($pr, 'PR archived');

        $this->assertSame(6500.0, $released);
        $this->assertSame('0.00', (string) $whA->fresh()->committed);
        $this->assertSame('0.00', (string) $whB->fresh()->committed);
    }

    public function test_spend_resolves_budget_by_po_location(): void
    {
        $whA = $this->makeBudget(10000);
        $whBId = $this->secondWarehouse();
        $whB = $this->makeBudget(8000, ['location_id' => $whBId]);
        $pr = $this->newShapePr(6500);
        $this->service->commitGroup($pr, $whA, 4000, null);
        $this->service->commitGroup($pr, $whB, 2500, null);

        $po = $this->makePo($pr, 2500, 11);
        $po->update(['location_id' => $whBId]);

        $this->service->spend($po->fresh(), null);

        $whA->refresh(); $whB->refresh();
        $this->assertSame('0.00', (string) $whA->spent);
        $this->assertSame('2500.00', (string) $whB->spent);
        $this->assertSame('4000.00', (string) $whA->committed);
        $this->assertSame('0.00', (string) $whB->committed);
    }
}
