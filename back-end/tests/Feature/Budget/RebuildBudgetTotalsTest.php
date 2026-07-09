<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;

class RebuildBudgetTotalsTest extends BudgetTestCase
{
    public function test_rebuild_recomputes_cached_columns_from_ledger(): void
    {
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);

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
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        $before = BudgetTransaction::where('budget_id', $budget->budget_id)->count();

        $this->artisan('budget:rebuild-totals --backfill')->assertSuccessful();

        $this->assertSame($before, BudgetTransaction::where('budget_id', $budget->budget_id)->count());
    }
}
