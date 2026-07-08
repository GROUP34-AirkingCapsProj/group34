<?php

namespace App\Services;

use App\Exceptions\InsufficientBudgetException;
use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Support\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    /** Utilization crossings that fire a realtime alert. */
    public const ALERT_THRESHOLDS = [0.80, 1.00];

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

            $utilBefore = $this->utilization($budget);

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

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /**
     * Release whatever commitment is still outstanding for this PR
     * (Σ its commit rows − Σ its release rows). Returns the released amount.
     * Safe to call repeatedly and on PRs that never committed anything.
     */
    public function releaseOutstanding(PurchaseRequest $pr, string $note, ?int $userId = null): float
    {
        if (!$pr->budget_id) {
            return 0.0;
        }

        return DB::transaction(function () use ($pr, $note, $userId) {
            $budget = BudgetAllocation::whereKey($pr->budget_id)->lockForUpdate()->first();
            if (!$budget) {
                return 0.0;
            }

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            if ($outstanding < 0) {
                Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PR {$pr->pr_id} on budget {$budget->budget_id}");
            }
            if ($outstanding <= 0) {
                return 0.0;
            }

            $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $pr->pr_id, null, $userId, $note);
            $newCommitted = round((float) $budget->committed - $outstanding, 2);
            if ($newCommitted < 0) {
                Log::warning("Budget ledger anomaly: committed clamped from {$newCommitted} to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
            $budget->save();

            return $outstanding;
        });
    }

    /**
     * Record actual spend for a completed PO and consume the PR's commitment.
     * Idempotent per PO (a second call is a no-op). For fully-converted PRs
     * (status 14, converted_to_po_id = this PO) any estimate surplus is
     * auto-returned to available.
     */
    public function spend(PurchaseOrder $po, ?int $userId = null): void
    {
        $pr = $po->purchaseRequest;
        if (!$pr || !$pr->budget_id) {
            return;
        }

        DB::transaction(function () use ($po, $pr, $userId) {
            $budget = BudgetAllocation::whereKey($pr->budget_id)->lockForUpdate()->first();
            if (!$budget) {
                return;
            }
            if (BudgetTransaction::where('po_id', $po->po_id)
                ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
                return; // already recorded (e.g. receiving flow + manual status update)
            }

            $utilBefore = $this->utilization($budget);

            $amount = (float) $po->total_amount;
            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $amount, $pr->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' completed');
            $budget->spent = round((float) $budget->spent + $amount, 2);

            $outstanding = $this->outstanding($budget->budget_id, $pr->pr_id);
            if ($outstanding < 0) {
                Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PR {$pr->pr_id} on budget {$budget->budget_id}");
            }
            $released = 0.0;

            $consume = round(min($outstanding, $amount), 2);
            if ($consume > 0) {
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $consume, $pr->pr_id, $po->po_id, $userId,
                    'Commitment consumed by PO ' . $po->po_number);
                $released += $consume;
            }

            // Fully-converted PR: return the estimate surplus to available.
            if ((int) $pr->status_id === 14 && (int) $pr->converted_to_po_id === (int) $po->po_id) {
                $surplus = round($outstanding - $consume, 2);
                if ($surplus > 0) {
                    $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $surplus, $pr->pr_id, $po->po_id, $userId,
                        'Estimate surplus returned');
                    $released += $surplus;
                }
            }

            $newCommitted = round((float) $budget->committed - $released, 2);
            if ($newCommitted < 0) {
                Log::warning("Budget ledger anomaly: committed clamped from {$newCommitted} to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
            $budget->save();

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /** Net commitment still held by a PR: Σcommit − Σrelease (both keyed to pr_id). */
    private function outstanding(int $budgetId, int $prId): float
    {
        $commits = (float) BudgetTransaction::where('budget_id', $budgetId)
            ->where('pr_id', $prId)->where('txn_type', BudgetTransaction::TYPE_COMMIT)->sum('amount');
        $releases = (float) BudgetTransaction::where('budget_id', $budgetId)
            ->where('pr_id', $prId)->where('txn_type', BudgetTransaction::TYPE_RELEASE)->sum('amount');

        return round($commits - $releases, 2);
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

    /** (committed + spent) / total — 1.0 for zero/negative totals. */
    private function utilization(BudgetAllocation $budget): float
    {
        $total = (float) $budget->total_budget;
        if ($total <= 0) {
            return 1.0;
        }

        return ((float) $budget->committed + (float) $budget->spent) / $total;
    }

    /** Fire one realtime alert per threshold crossed by this mutation. */
    private function maybeAlert(BudgetAllocation $budget, float $utilBefore): void
    {
        $after = $this->utilization($budget);
        foreach (self::ALERT_THRESHOLDS as $threshold) {
            if ($utilBefore < $threshold && $after >= $threshold) {
                RealtimeBroadcaster::budgetChanged((int) $budget->location_id, 'budget_threshold', [
                    'budget_id'       => $budget->budget_id,
                    'period_label'    => $budget->period_label,
                    'utilization_pct' => (int) round($after * 100),
                ]);
            }
        }
    }
}
