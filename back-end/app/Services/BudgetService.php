<?php

namespace App\Services;

use App\Exceptions\InsufficientBudgetException;
use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code allowed to mutate budget_allocations.committed / spent.
 * Every mutation is journaled as an immutable budget_transactions row:
 *   commit     → committed += amount   (PR approved)
 *   release    → committed -= amount   (PR archived / commitment consumed by spend / surplus)
 *   spend      → spent     += amount   (PO completed)
 *   adjustment → informational          (total_budget changed by Admin)
 */
class BudgetService
{
    /** Read-only availability check (no lock) — used at PR submission time. */
    public function hasAvailable(BudgetAllocation $budget, float $amount): bool
    {
        return $amount <= (float) $budget->total_budget
            - (float) $budget->committed
            - (float) $budget->spent;
    }

    /** Most recently started budget whose period covers today, for a location. */
    public function resolveActiveBudget(int $locationId): ?BudgetAllocation
    {
        $today = today();

        return BudgetAllocation::where('location_id', $locationId)
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * Commit the PR's estimated total against its budget (PR approval).
     * Locks the budget row; throws InsufficientBudgetException when it cannot cover.
     */
    public function commit(PurchaseRequest $pr, ?int $userId = null): void
    {
        DB::transaction(function () use ($pr, $userId) {
            $budget = BudgetAllocation::whereKey($pr->budget_id)->lockForUpdate()->firstOrFail();

            if (BudgetTransaction::where('pr_id', $pr->pr_id)
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->exists()) {
                return; // already committed (retry / double-submit)
            }

            $amount = (float) $pr->estimated_total;
            $available = (float) $budget->total_budget
                - (float) $budget->committed
                - (float) $budget->spent;

            if ($amount > $available) {
                throw new InsufficientBudgetException($budget->period_label, $amount, $available);
            }

            $this->txn($budget, BudgetTransaction::TYPE_COMMIT, $amount, $pr->pr_id, null, $userId,
                'PR ' . $pr->pr_number . ' approved');
            $budget->committed = round((float) $budget->committed + $amount, 2);
            $budget->save();
        });
    }

    private function txn(
        BudgetAllocation $budget,
        string $type,
        float $amount,
        ?int $prId,
        ?int $poId,
        ?int $userId,
        ?string $note
    ): void {
        BudgetTransaction::create([
            'budget_id'    => $budget->budget_id,
            'txn_type'     => $type,
            'amount'       => round($amount, 2),
            'pr_id'        => $prId,
            'po_id'        => $poId,
            'performed_by' => $userId,
            'notes'        => $note,
        ]);
    }
}
