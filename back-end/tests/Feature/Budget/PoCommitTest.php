<?php

namespace Tests\Feature\Budget;

use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\NoActiveBudgetException;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class PoCommitTest extends BudgetTestCase
{
    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BudgetService::class);
    }

    private function addPoLine(PurchaseOrder $po, float $subtotal, ?int $locationId = null): void
    {
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

    private function anyProductId(): int
    {
        return (int) (DB::table('products')->value('product_id')
            ?? DB::table('products')->insertGetId([
                'product_name' => 'Budget Test Product ' . uniqid(),
                'product_code' => 'BTP-' . strtoupper(substr(uniqid(), -6)),
                'product_type' => 'installation_material',
                'created_at'   => now(),
                'updated_at'   => now(),
            ], 'product_id'));
    }

    public function test_commit_po_reserves_from_line_subtotals(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 2500);
        $this->addPoLine($po, 1500);

        $this->service->commitPo($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $txn = BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'commit')->first();
        $this->assertNotNull($txn);
        $this->assertSame('4000.00', (string) $txn->amount);
        $this->assertEquals($budget->budget_id, $txn->budget_id);
    }

    public function test_commit_po_falls_back_to_total_amount_when_no_lines(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 3200, 7);

        $this->service->commitPo($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('3200.00', (string) $budget->committed);
    }

    public function test_commit_po_is_idempotent_per_po(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->service->commitPo($po->fresh()->load('details'));
        $this->service->commitPo($po->fresh()->load('details'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'commit')->count());
    }

    public function test_commit_po_throws_when_insufficient(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 7000]);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->expectException(InsufficientBudgetException::class);
        $this->service->commitPo($po->fresh()->load('details'));
    }

    public function test_commit_po_throws_when_no_active_budget_covers_today(): void
    {
        $budget = $this->makeBudget(10000, [
            'period_start' => today()->subDays(60)->toDateString(),
            'period_end'   => today()->subDays(30)->toDateString(),
        ]);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        $this->addPoLine($po, 4000);

        $this->expectException(NoActiveBudgetException::class);
        $this->service->commitPo($po->fresh()->load('details'));
    }
}
