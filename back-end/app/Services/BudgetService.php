<?php

namespace App\Services;

use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\UnverifiedBudgetException;
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
            ->where('verification_status', 'verified')
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * Legacy single-budget commit — commits the PR's whole estimated_total to
     * its header budget_id. Kept for pre-existing single-warehouse PRs.
     */
    public function commit(PurchaseRequest $pr, ?int $userId = null): void
    {
        $budget = BudgetAllocation::find($pr->budget_id);
        if (!$budget) {
            throw new \RuntimeException('Purchase request has no budget to commit against.');
        }
        $this->commitGroup($pr, $budget, (float) $pr->estimated_total, $userId);
    }

    /**
     * Commit a specific amount for a PR against one budget (one warehouse group).
     * Row-locked; idempotent per (budget_id, pr_id); requires the budget verified
     * with enough available. Throws Unverified/Insufficient exceptions.
     */
    public function commitGroup(PurchaseRequest $pr, BudgetAllocation $budget, float $amount, ?int $userId = null): void
    {
        DB::transaction(function () use ($pr, $budget, $amount, $userId) {
            $locked = BudgetAllocation::whereKey($budget->budget_id)->lockForUpdate()->firstOrFail();

            if (BudgetTransaction::where('pr_id', $pr->pr_id)
                ->where('budget_id', $locked->budget_id)
                ->where('txn_type', BudgetTransaction::TYPE_COMMIT)->exists()) {
                return; // already committed this group (retry / double-submit)
            }

            if ($locked->verification_status !== 'verified') {
                throw new UnverifiedBudgetException($locked->period_label, $locked->verification_status);
            }

            $utilBefore = $this->utilization($locked);

            $available = (float) $locked->total_budget - (float) $locked->committed - (float) $locked->spent;
            if ($amount > $available) {
                throw new InsufficientBudgetException($locked->period_label, $amount, $available);
            }

            $this->txn($locked, BudgetTransaction::TYPE_COMMIT, $amount, $pr->pr_id, null, $userId,
                'PR ' . $pr->pr_number . ' approved');
            $locked->committed = round((float) $locked->committed + $amount, 2);
            $locked->save();

            $this->maybeAlert($locked, $utilBefore);
        });
    }

    /**
     * Release whatever commitment is still outstanding for this PR
     * (Σ its commit rows − Σ its release rows). Returns the released amount.
     * Safe to call repeatedly and on PRs that never committed anything.
     */
    public function releaseOutstanding(PurchaseRequest $pr, string $note, ?int $userId = null): float
    {
        $budgetIds = BudgetTransaction::where('pr_id', $pr->pr_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');

        if ($budgetIds->isEmpty() && $pr->budget_id) {
            $budgetIds = collect([$pr->budget_id]);
        }

        $total = 0.0;
        foreach ($budgetIds as $budgetId) {
            $total += DB::transaction(function () use ($budgetId, $pr, $note, $userId) {
                $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
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
        return round($total, 2);
    }

    /**
     * Record actual spend for a completed PO and consume the PR's commitment.
     * Idempotent per PO (a second call is a no-op). For fully-converted PRs
     * (status 14, converted_to_po_id = this PO) any estimate surplus is
     * auto-returned to available — or when the PO fully covers its warehouse
     * group's PR lines.
     */
    public function spend(PurchaseOrder $po, ?int $userId = null): void
    {
        $pr = $po->purchaseRequest;
        if (!$pr) {
            return;
        }

        $budgetId = $this->resolveBudgetForPo($pr, $po);
        if (!$budgetId) {
            return;
        }

        DB::transaction(function () use ($po, $pr, $budgetId, $userId) {
            $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
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

            // Return the estimate surplus to available when the group is fully ordered:
            // legacy fully-converted PR, or a PO that fully covers its warehouse group's lines.
            $coversGroup = ((int) $pr->status_id === 14 && (int) $pr->converted_to_po_id === (int) $po->po_id)
                || $this->poCoversItsGroup($pr, $po);
            if ($coversGroup) {
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

    /**
     * The budget this PO settles against: among budgets the PR committed to,
     * the one whose location matches the PO's warehouse. Legacy fallback:
     * the PR's header budget_id.
     */
    private function resolveBudgetForPo(PurchaseRequest $pr, PurchaseOrder $po): ?int
    {
        $committedBudgetIds = BudgetTransaction::where('pr_id', $pr->pr_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');

        if ($committedBudgetIds->isNotEmpty()) {
            $match = BudgetAllocation::whereIn('budget_id', $committedBudgetIds)
                ->where('location_id', $po->location_id)
                ->value('budget_id');
            if ($match) {
                return (int) $match;
            }
        }
        return $pr->budget_id ? (int) $pr->budget_id : null;
    }

    /**
     * True when this PO's ordered quantities cover every PR line destined for
     * the PO's warehouse (so the group is fully ordered and surplus can return).
     * Legacy PRs (no line locations) compare against ALL lines.
     */
    private function poCoversItsGroup(PurchaseRequest $pr, PurchaseOrder $po): bool
    {
        $hasLineLocations = $pr->details()->whereNotNull('location_id')->exists();
        $prLines = $pr->details()
            ->when($hasLineLocations, fn ($q) => $q->where('location_id', $po->location_id))
            ->get();
        if ($prLines->isEmpty()) {
            return false;
        }
        $ordered = $po->details()->get()->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity_ordered'));
        foreach ($prLines as $line) {
            if (($ordered[$line->product_id] ?? 0) < (int) $line->quantity) {
                return false;
            }
        }
        return true;
    }

    /**
     * Change total_budget with an audit trail. The total can never drop below
     * committed + spent (funds already promised or gone).
     * @throws \InvalidArgumentException
     */
    public function adjustTotal(BudgetAllocation $budget, float $newTotal, ?int $userId = null, ?string $note = null): void
    {
        DB::transaction(function () use ($budget, $newTotal, $userId, $note) {
            $locked = BudgetAllocation::whereKey($budget->budget_id)->lockForUpdate()->firstOrFail();

            $floor = (float) $locked->committed + (float) $locked->spent;
            if ($newTotal < $floor) {
                throw new \InvalidArgumentException(sprintf(
                    'Total budget cannot be lower than committed + spent (₱%s)',
                    number_format($floor, 2)
                ));
            }

            $old = (float) $locked->total_budget;
            if (abs($newTotal - $old) < 0.005) {
                return;
            }

            $this->txn($locked, BudgetTransaction::TYPE_ADJUSTMENT, abs($newTotal - $old), null, null, $userId,
                $note ?? sprintf(
                    'Total budget %s from ₱%s to ₱%s',
                    $newTotal > $old ? 'increased' : 'decreased',
                    number_format($old, 2),
                    number_format($newTotal, 2)
                ));
            $locked->total_budget = round($newTotal, 2);
            $locked->verification_status = 'pending';
            $locked->verified_by = null;
            $locked->verified_at = null;
            $locked->verification_note = 'Auto-reset: total changed, awaiting re-verification';
            $locked->save();

            // The amount an auditor vouched for changed — alert verifiers to re-check.
            RealtimeBroadcaster::budgetChanged((int) $locked->location_id, 'verification_pending', [
                'budget_id' => $locked->budget_id,
            ]);
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
