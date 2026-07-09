<?php

namespace App\Console\Commands;

use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Console\Command;

class RebuildBudgetTotals extends Command
{
    protected $signature = 'budget:rebuild-totals
        {--backfill : Synthesize ledger rows from live PR/PO state for budgets that have no transactions yet}';

    protected $description = 'Recompute budget_allocations.committed/spent from the budget_transactions ledger';

    public function handle(): int
    {
        if ($this->option('backfill')) {
            $this->backfill();
        }

        foreach (BudgetAllocation::withTrashed()->get() as $budget) {
            $sum = fn (string $type): float => (float) BudgetTransaction::where('budget_id', $budget->budget_id)
                ->where('txn_type', $type)->sum('amount');

            $budget->committed = round(max(0, $sum(BudgetTransaction::TYPE_COMMIT) - $sum(BudgetTransaction::TYPE_RELEASE)), 2);
            $budget->spent     = round($sum(BudgetTransaction::TYPE_SPEND), 2);
            $budget->save();

            $this->line(sprintf(
                '%s [%s]: committed=%s spent=%s',
                $budget->period_label,
                $budget->budget_id,
                number_format((float) $budget->committed, 2),
                number_format((float) $budget->spent, 2)
            ));
        }

        $this->info('Budget totals rebuilt from ledger.');

        return self::SUCCESS;
    }

    /**
     * For budgets with no ledger rows yet (created before the ledger existed),
     * synthesize commit/spend/release rows from the live PR/PO state so the
     * rebuild has something truthful to sum.
     */
    private function backfill(): void
    {
        $withLedger = BudgetTransaction::distinct()->pluck('budget_id');
        $virgin = BudgetAllocation::withTrashed()->whereNotIn('budget_id', $withLedger)->get();

        foreach ($virgin as $budget) {
            $prs = PurchaseRequest::withTrashed()
                ->where('budget_id', $budget->budget_id)
                ->whereNull('deleted_at')          // archived PRs hold no commitment
                ->whereIn('status_id', [14, 16])   // approved / partially converted
                ->get();

            foreach ($prs as $pr) {
                BudgetTransaction::create([
                    'budget_id' => $budget->budget_id,
                    'txn_type'  => BudgetTransaction::TYPE_COMMIT,
                    'amount'    => round((float) $pr->estimated_total, 2),
                    'pr_id'     => $pr->pr_id,
                    'notes'     => 'Backfill: PR ' . $pr->pr_number . ' approved',
                ]);

                $remaining = (float) $pr->estimated_total;
                $completedPos = PurchaseOrder::where('pr_id', $pr->pr_id)->where('status_id', 11)->get();

                foreach ($completedPos as $po) {
                    $amount = (float) $po->total_amount;
                    BudgetTransaction::create([
                        'budget_id' => $budget->budget_id,
                        'txn_type'  => BudgetTransaction::TYPE_SPEND,
                        'amount'    => round($amount, 2),
                        'pr_id'     => $pr->pr_id,
                        'po_id'     => $po->po_id,
                        'notes'     => 'Backfill: PO ' . $po->po_number . ' completed',
                    ]);

                    $consume = round(min($remaining, $amount), 2);
                    if ($consume > 0) {
                        BudgetTransaction::create([
                            'budget_id' => $budget->budget_id,
                            'txn_type'  => BudgetTransaction::TYPE_RELEASE,
                            'amount'    => $consume,
                            'pr_id'     => $pr->pr_id,
                            'po_id'     => $po->po_id,
                            'notes'     => 'Backfill: commitment consumed by PO ' . $po->po_number,
                        ]);
                        $remaining = round($remaining - $consume, 2);
                    }

                    // Fully-converted PR whose final PO is done: return estimate surplus.
                    if ((int) $pr->status_id === 14
                        && (int) $pr->converted_to_po_id === (int) $po->po_id
                        && $remaining > 0) {
                        BudgetTransaction::create([
                            'budget_id' => $budget->budget_id,
                            'txn_type'  => BudgetTransaction::TYPE_RELEASE,
                            'amount'    => $remaining,
                            'pr_id'     => $pr->pr_id,
                            'po_id'     => $po->po_id,
                            'notes'     => 'Backfill: estimate surplus returned',
                        ]);
                        $remaining = 0.0;
                    }
                }
            }

            $this->line('Backfilled ledger for budget ' . $budget->budget_id . ' (' . $budget->period_label . ')');
        }
    }
}
