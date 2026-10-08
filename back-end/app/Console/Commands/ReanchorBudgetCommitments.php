<?php

namespace App\Console\Commands;

use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cutover: move budget reservations from PR-anchored holds to
 * PO-anchored reservations. Writes ledger rows only; run budget:rebuild-totals
 * afterwards to recompute cached committed/spent columns.
 *
 * Scope: releases every PR-anchored COMMIT hold (po_id IS NULL) that still has
 * outstanding balance, and reserves every PO currently Approved(8)/Authorized(10)
 * that has no PO-anchored commit yet. Completed(11)/Partially-Received(12) POs keep
 * their already-recorded spend and are not re-reserved.
 */
class ReanchorBudgetCommitments extends Command
{
    protected $signature = 'budget:reanchor-commitments';
    protected $description = 'Move budget reservations from PR-anchored holds to PO-anchored reservations';

    public function handle(): int
    {
        $this->releasePrHolds();
        $this->reserveApprovedPos();
        $this->info('Reanchor complete. Now run: php artisan budget:rebuild-totals');
        return self::SUCCESS;
    }

    /** Release all outstanding PR-anchored commit holds (po_id IS NULL). */
    private function releasePrHolds(): void
    {
        $groups = BudgetTransaction::whereNull('po_id')
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->select('budget_id', 'pr_id')->distinct()->get();

        foreach ($groups as $g) {
            $commits = (float) BudgetTransaction::where('budget_id', $g->budget_id)
                ->where('pr_id', $g->pr_id)->whereNull('po_id')
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->sum('amount');
            $releases = (float) BudgetTransaction::where('budget_id', $g->budget_id)
                ->where('pr_id', $g->pr_id)->whereNull('po_id')
                ->where('txn_type', BudgetTransaction::TYPE_RELEASE)->sum('amount');
            $outstanding = round($commits - $releases, 2);
            if ($outstanding <= 0) {
                continue;
            }
            BudgetTransaction::create([
                'budget_id' => $g->budget_id,
                'txn_type'  => BudgetTransaction::TYPE_RELEASE,
                'amount'    => $outstanding,
                'pr_id'     => $g->pr_id,
                'po_id'     => null,
                'notes'     => 'Reanchor: released PR-anchored hold',
            ]);
            $this->line("Released PR hold pr={$g->pr_id} budget={$g->budget_id} amount={$outstanding}");
        }
    }

    /** Reserve each Approved/Authorized PO that has no PO-anchored commit yet. */
    private function reserveApprovedPos(): void
    {
        $pos = PurchaseOrder::with('details')->whereIn('status_id', [8, 10])->get();
        foreach ($pos as $po) {
            $already = BudgetTransaction::where('po_id', $po->po_id)
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->exists();
            if ($already) {
                continue;
            }
            $groups = $po->details->isNotEmpty()
                ? $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
                : collect([(int) $po->location_id => collect()]);

            foreach ($groups as $locationId => $lines) {
                $amount = $lines->isNotEmpty()
                    ? round((float) $lines->sum('subtotal'), 2)
                    : round((float) $po->total_amount, 2);
                $budgetId = DB::table('budget_allocations')
                    ->where('location_id', $locationId)
                    ->whereDate('period_start', '<=', today())
                    ->whereDate('period_end', '>=', today())
                    ->orderByDesc('period_start')
                    ->value('budget_id');
                if (!$budgetId) {
                    $this->warn("No active budget for location {$locationId} (PO {$po->po_id}) — skipped");
                    continue;
                }
                BudgetTransaction::create([
                    'budget_id' => $budgetId,
                    'txn_type'  => BudgetTransaction::TYPE_COMMIT,
                    'amount'    => $amount,
                    'pr_id'     => $po->pr_id,
                    'po_id'     => $po->po_id,
                    'notes'     => 'Reanchor: PO ' . $po->po_number . ' reserved',
                ]);
                $this->line("Reserved PO po={$po->po_id} budget={$budgetId} amount={$amount}");
            }
        }
    }
}
