<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class RebuildBudgetTotalsTest extends BudgetTestCase
{
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

    private function commitPoOf(\App\Models\BudgetAllocation $budget, float $amount): void
    {
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, $amount, 7);
        DB::table('purchase_order_details')->insert([
            'po_id'            => $po->po_id,
            'product_id'       => $this->anyProductId(),
            'quantity_ordered' => 1,
            'unit_price'       => $amount,
            'subtotal'         => $amount,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        app(BudgetService::class)->commitPo($po->fresh()->load('details'));
    }

    public function test_rebuild_recomputes_cached_columns_from_ledger(): void
    {
        $budget = $this->makeBudget(10000);
        $this->commitPoOf($budget, 4000);

        // Corrupt the cached columns to simulate historical drift.
        $budget->forceFill(['committed' => 9999, 'spent' => 123])->save();

        $this->artisan('budget:rebuild-totals')->assertSuccessful();

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->committed);
        $this->assertSame('0.00', (string) $budget->spent);
    }

    public function test_backfill_synthesizes_ledger_for_pre_ledger_budgets(): void
    {
        $budget = $this->makeBudget(10000, ['committed' => 500, 'spent' => 700]); // drifted junk
        $pr = $this->makePr($budget, 4000, 14);                                   // approved, converted
        $po = $this->makePo($pr, 3500, 11);                                       // completed PO
        $pr->update(['converted_to_po_id' => $po->po_id]);
        $prOpen = $this->makePr($budget, 2000, 14);                               // approved, no PO yet

        $this->artisan('budget:rebuild-totals --backfill')->assertSuccessful();

        $budget->refresh();
        // pr: commit 4000, spend 3500, consume 3500, surplus 500 → outstanding 0
        // prOpen: commit 2000 outstanding
        $this->assertSame('2000.00', (string) $budget->committed);
        $this->assertSame('3500.00', (string) $budget->spent);
        $this->assertGreaterThan(0, BudgetTransaction::where('budget_id', $budget->budget_id)->count());
    }

    public function test_backfill_skips_budgets_that_already_have_ledger_rows(): void
    {
        $budget = $this->makeBudget(10000);
        $this->commitPoOf($budget, 4000);
        $before = BudgetTransaction::where('budget_id', $budget->budget_id)->count();

        $this->artisan('budget:rebuild-totals --backfill')->assertSuccessful();

        $this->assertSame($before, BudgetTransaction::where('budget_id', $budget->budget_id)->count());
    }
}
