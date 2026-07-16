<?php

namespace App\Services;

use App\Exceptions\InsufficientBudgetException;
use App\Exceptions\UnverifiedBudgetException;
use App\Models\BudgetAllocation;
use App\Models\BudgetTransaction;
use App\Models\PurchaseOrder;
use App\Support\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY code allowed to mutate budget_allocations.committed / spent.
 * Every mutation is journaled as an immutable budget_transactions row:
 *   commit  → committed += amount   (PO approved)
 *   release → committed -= amount   (PO settled / rejected)
 *   spend   → spent     += amount   (PO received)
 *   adjustment → informational      (total_budget changed by Admin)
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
     * Reserve budget for an approved PO, per destination warehouse. Row-locked,
     * idempotent per (budget_id, po_id); requires a verified active budget with
     * enough available. Throws Unverified/Insufficient. PR-agnostic (direct POs too).
     */
    public function commitPo(PurchaseOrder $po, ?int $userId = null): void
    {
        $po->loadMissing('details');
        $groups = $po->details->isNotEmpty()
            ? $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
            : collect([(int) $po->location_id => collect()]);

        foreach ($groups as $locationId => $lines) {
            $amount = $lines->isNotEmpty()
                ? round((float) $lines->sum('subtotal'), 2)
                : round((float) $po->total_amount, 2);
            $this->commitPoGroup($po, (int) $locationId, $amount, $userId);
        }
    }

    /** One warehouse group of a PO reservation. */
    private function commitPoGroup(PurchaseOrder $po, int $locationId, float $amount, ?int $userId): void
    {
        $budget = $this->resolveActiveBudget($locationId);
        if (!$budget) {
            $whName = \App\Models\Location::where('location_id', $locationId)->value('location_name');
            throw new UnverifiedBudgetException($whName ?? "warehouse #{$locationId}", 'pending');
        }

        DB::transaction(function () use ($po, $budget, $amount, $userId) {
            $locked = BudgetAllocation::whereKey($budget->budget_id)->lockForUpdate()->firstOrFail();

            // Idempotent per (budget, po): skip only if this PO still holds a live
            // reservation on this budget. A PO whose reservation was fully released
            // (e.g. rejected then re-approved) must be able to reserve again.
            if ($this->outstandingForPo($locked->budget_id, $po->po_id) > 0) {
                return;
            }
            if ($locked->verification_status !== 'verified') {
                throw new UnverifiedBudgetException($locked->period_label, $locked->verification_status);
            }

            $utilBefore = $this->utilization($locked);
            $available = (float) $locked->total_budget - (float) $locked->committed - (float) $locked->spent;
            if ($amount > $available) {
                throw new InsufficientBudgetException($locked->period_label, $amount, $available);
            }

            $this->txn($locked, BudgetTransaction::TYPE_COMMIT, $amount, $po->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' approved');
            $locked->committed = round((float) $locked->committed + $amount, 2);
            $locked->save();

            $this->maybeAlert($locked, $utilBefore);
        });
    }

    /** Net reservation still held by a PO on a budget: Σcommit − Σrelease (po_id-keyed). */
    private function outstandingForPo(int $budgetId, int $poId): float
    {
        $commits = (float) BudgetTransaction::where('budget_id', $budgetId)
            ->where('po_id', $poId)->where('txn_type', BudgetTransaction::TYPE_COMMIT)->sum('amount');
        $releases = (float) BudgetTransaction::where('budget_id', $budgetId)
            ->where('po_id', $poId)->where('txn_type', BudgetTransaction::TYPE_RELEASE)->sum('amount');

        return round($commits - $releases, 2);
    }

    /** The budget a PO reserved against for a warehouse: the one with a COMMIT txn for this po_id at that location. */
    private function resolveBudgetForPo(int $poId, int $locationId): ?int
    {
        $committedBudgetIds = BudgetTransaction::where('po_id', $poId)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');
        if ($committedBudgetIds->isEmpty()) {
            return null;
        }
        $match = BudgetAllocation::whereIn('budget_id', $committedBudgetIds)
            ->where('location_id', $locationId)->value('budget_id');

        return $match ? (int) $match : null;
    }

    /**
     * Record actual spend for a received PO and release the PO's own reservation.
     * Idempotent per (po_id[, budget_id]). PR-agnostic — direct POs settle too.
     */
    public function spend(PurchaseOrder $po, ?int $userId = null): void
    {
        $po->loadMissing('details');
        $groups = $po->details->isNotEmpty()
            ? $po->details->groupBy(fn ($d) => (int) ($d->location_id ?? $po->location_id))
            : collect([(int) $po->location_id => collect()]);

        if ($groups->count() <= 1) {
            $this->spendWhole($po, $userId);
            return;
        }

        foreach ($groups as $locationId => $lines) {
            if (!$this->groupFullyReceived($lines)) {
                continue;
            }
            $this->spendGroup($po, (int) $locationId, $lines, $userId);
        }
    }

    /** Single-warehouse / legacy settlement against the PO's reservation. */
    private function spendWhole(PurchaseOrder $po, ?int $userId): void
    {
        $budgetId = $this->resolveBudgetForPo($po->po_id, (int) $po->location_id)
            ?? optional($this->resolveActiveBudget((int) $po->location_id))->budget_id;
        if (!$budgetId) {
            return;
        }

        DB::transaction(function () use ($po, $budgetId, $userId) {
            $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
            if (!$budget) {
                return;
            }
            if (BudgetTransaction::where('po_id', $po->po_id)
                ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
                return; // already recorded
            }

            $utilBefore = $this->utilization($budget);

            $amount = (float) $po->total_amount;
            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $amount, $po->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' completed');
            $budget->spent = round((float) $budget->spent + $amount, 2);

            $this->releaseReservationInLock($budget, $po, $userId, 'Reservation settled by PO ' . $po->po_number);

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /** One warehouse group of a multi-warehouse PO, keyed idempotently per (po, budget). */
    private function spendGroup(PurchaseOrder $po, int $locationId, $lines, ?int $userId): void
    {
        $budgetId = $this->resolveBudgetForPo($po->po_id, $locationId)
            ?? optional($this->resolveActiveBudget($locationId))->budget_id;
        if (!$budgetId) {
            return;
        }
        if (BudgetTransaction::where('po_id', $po->po_id)
            ->where('budget_id', $budgetId)
            ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
            return;
        }
        $groupAmount = round((float) collect($lines)->sum('subtotal'), 2);

        DB::transaction(function () use ($po, $budgetId, $groupAmount, $userId) {
            $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
            if (!$budget) {
                return;
            }
            if (BudgetTransaction::where('po_id', $po->po_id)
                ->where('budget_id', $budget->budget_id)
                ->where('txn_type', BudgetTransaction::TYPE_SPEND)->exists()) {
                return;
            }

            $utilBefore = $this->utilization($budget);

            $this->txn($budget, BudgetTransaction::TYPE_SPEND, $groupAmount, $po->pr_id, $po->po_id, $userId,
                'PO ' . $po->po_number . ' warehouse received');
            $budget->spent = round((float) $budget->spent + $groupAmount, 2);

            $this->releaseReservationInLock($budget, $po, $userId, 'Reservation settled by PO ' . $po->po_number . ' (warehouse)');

            $this->maybeAlert($budget, $utilBefore);
        });
    }

    /** Release this PO's remaining reservation on an already-locked budget row, then persist. */
    private function releaseReservationInLock(BudgetAllocation $budget, PurchaseOrder $po, ?int $userId, string $note): void
    {
        $outstanding = $this->outstandingForPo($budget->budget_id, $po->po_id);
        if ($outstanding < 0) {
            Log::warning("Budget ledger anomaly: negative outstanding {$outstanding} for PO {$po->po_id} on budget {$budget->budget_id}");
        }
        if ($outstanding > 0) {
            $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $po->pr_id, $po->po_id, $userId, $note);
            $newCommitted = round((float) $budget->committed - $outstanding, 2);
            if ($newCommitted < 0) {
                Log::warning("Budget ledger anomaly: committed clamped to 0 on budget {$budget->budget_id} (run budget:rebuild-totals)");
                $newCommitted = 0.0;
            }
            $budget->committed = $newCommitted;
        }
        $budget->save();
    }

    /** Release a PO's outstanding reservation back to available (on reject/cancel). Returns released total. */
    public function releaseCommitment(PurchaseOrder $po, string $note, ?int $userId = null): float
    {
        $budgetIds = BudgetTransaction::where('po_id', $po->po_id)
            ->where('txn_type', BudgetTransaction::TYPE_COMMIT)
            ->distinct()->pluck('budget_id');

        $total = 0.0;
        foreach ($budgetIds as $budgetId) {
            $total += DB::transaction(function () use ($budgetId, $po, $note, $userId) {
                $budget = BudgetAllocation::whereKey($budgetId)->lockForUpdate()->first();
                if (!$budget) {
                    return 0.0;
                }
                $outstanding = $this->outstandingForPo($budget->budget_id, $po->po_id);
                if ($outstanding <= 0) {
                    return 0.0;
                }
                $this->txn($budget, BudgetTransaction::TYPE_RELEASE, $outstanding, $po->pr_id, $po->po_id, $userId, $note);
                $newCommitted = round((float) $budget->committed - $outstanding, 2);
                if ($newCommitted < 0) {
                    $newCommitted = 0.0;
                }
                $budget->committed = $newCommitted;
                $budget->save();
                return $outstanding;
            });
        }
        return round($total, 2);
    }

    /** All of a warehouse group's PO lines are fully received. */
    private function groupFullyReceived($lines): bool
    {
        $detailIds = collect($lines)->pluck('po_detail_id');
        $received = \App\Models\ReceivingDetail::whereIn('po_detail_id', $detailIds)
            ->get()->groupBy('po_detail_id')
            ->map(fn ($r) => (int) $r->sum('quantity_amount'));
        foreach ($lines as $line) {
            if (($received[$line->po_detail_id] ?? 0) < (int) $line->quantity_ordered) {
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
