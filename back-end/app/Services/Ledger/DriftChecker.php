<?php

namespace App\Services\Ledger;

use App\Models\LedgerDriftFinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Proves the ledger still agrees with the rest of the system, so a write path that
 * forgets to post becomes a visible audit finding instead of silent corruption.
 *
 * Three comparisons, each written so agreement actually means something: the source
 * tables, the operational FIFO record and live stock were all written by different
 * code for different reasons, so they only agree if the ledger is right.
 */
class DriftChecker
{
    /** Below this, treat as equal — rounding, not drift. */
    private const TOLERANCE = 0.01;

    /** @return array<int,LedgerDriftFinding> */
    public function run(Carbon $from, Carbon $to): array
    {
        return array_merge(
            $this->sourceVsLedger($from, $to),
            $this->ledgerVsMovements($from, $to),
            $this->ledgerVsStock(),
        );
    }

    /** @return array<int,LedgerDriftFinding> */
    private function sourceVsLedger(Carbon $from, Carbon $to): array
    {
        $expected = [
            'receivings' => (float) DB::table('receiving_details as rd')
                ->join('receivings as r', 'r.receiving_id', '=', 'rd.receiving_id')
                ->whereBetween('r.receiving_date', [$from->toDateString(), $to->toDateString()])
                ->sum('rd.quantity_amount'),
            'adjustments' => (float) DB::table('adjustment_details as ad')
                ->join('adjustments as a', 'a.adjustment_id', '=', 'ad.adjustment_id')
                ->whereBetween('a.adjustment_date', [$from->toDateString(), $to->toDateString()])
                ->sum(DB::raw('ABS(COALESCE(ad.add_quantity, 0) - COALESCE(ad.deduct_quantity, 0))')),
        ];

        $findings = [];

        foreach ($expected as $sourceType => $expectedQty) {
            $actualQty = (float) DB::table('inventory_ledger')
                ->where('source_type', $sourceType)
                ->whereNull('reverses_ledger_id')
                ->whereBetween('occurred_at', [$from, $to])
                ->sum('quantity');

            $variance = round($expectedQty - $actualQty, 2);

            if (abs($variance) <= self::TOLERANCE) {
                continue;
            }

            $findings[] = $this->record(
                'source_vs_ledger', $sourceType, $from, $to, $expectedQty, $actualQty, $variance, null,
                $variance > 0
                    ? "{$variance} pieces of {$sourceType} activity are recorded in the source tables but not in the ledger."
                    : abs($variance) . " pieces of ledger {$sourceType} activity have no matching source rows."
            );
        }

        return $findings;
    }

    /** @return array<int,LedgerDriftFinding> */
    private function ledgerVsMovements(Carbon $from, Carbon $to): array
    {
        $expected = (float) DB::table('inventory_batch_movements')
            ->whereNotNull('issuance_id')
            ->whereBetween('moved_at', [$from, $to])
            ->sum('quantity');

        $actual = (float) DB::table('inventory_ledger')
            ->where('source_type', 'issuances')
            ->where('direction', 'out')
            ->whereNull('reverses_ledger_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('quantity');

        $variance = round($expected - $actual, 2);

        if (abs($variance) <= self::TOLERANCE) {
            return [];
        }

        return [$this->record(
            'ledger_vs_movements', 'issuances', $from, $to, $expected, $actual, $variance, null,
            'The operational FIFO record and the ledger disagree about how much stock was issued.'
        )];
    }

    /**
     * Net ledger quantity per product and location against live inventory.
     *
     * Only compared where the ledger has coverage at all: a product whose entire
     * history predates the ledger would otherwise report its whole opening balance
     * as drift, which is noise rather than a finding.
     *
     * @return array<int,LedgerDriftFinding>
     */
    private function ledgerVsStock(): array
    {
        $ledgerNet = DB::table('inventory_ledger')
            ->selectRaw("product_id, COALESCE(to_location_id, from_location_id) as location_id,
                SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as net")
            ->whereNotNull('product_id')
            ->groupBy('product_id', DB::raw('COALESCE(to_location_id, from_location_id)'))
            ->get()
            ->keyBy(fn ($r) => $r->product_id . ':' . $r->location_id);

        if ($ledgerNet->isEmpty()) {
            return [];
        }

        $findings = [];

        $stock = DB::table('inventory')
            ->select(['product_id', 'location_id', 'quantity_on_hand'])
            ->whereIn('product_id', $ledgerNet->pluck('product_id')->unique())
            ->get();

        foreach ($stock as $row) {
            $key = $row->product_id . ':' . $row->location_id;
            if (! $ledgerNet->has($key)) {
                continue;
            }

            $expected = (float) $ledgerNet[$key]->net;
            $actual   = (float) $row->quantity_on_hand;
            $variance = round($expected - $actual, 2);

            if (abs($variance) <= self::TOLERANCE) {
                continue;
            }

            $findings[] = $this->record(
                'ledger_vs_stock', 'inventory', null, null, $expected, $actual, $variance,
                ['product_id' => (int) $row->product_id, 'location_id' => (int) $row->location_id],
                'Net recorded movement does not match stock on hand; stock may have moved without being recorded.'
            );
        }

        return $findings;
    }

    /**
     * Write a finding, unless an identical one is already open.
     *
     * Re-running the check must not bury the auditor in duplicates of one
     * unresolved fact — an exceptions list nobody can read is the same as no
     * exceptions list.
     */
    private function record(
        string $checkType,
        ?string $sourceType,
        ?Carbon $from,
        ?Carbon $to,
        float $expected,
        float $actual,
        float $variance,
        ?array $scope,
        string $detail
    ): LedgerDriftFinding {
        $existing = LedgerDriftFinding::where('check_type', $checkType)
            ->where('source_type', $sourceType)
            ->whereNull('resolved_at')
            ->when($from, fn ($q) => $q->whereDate('period_start', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('period_end', $to->toDateString()))
            ->when($scope === null, fn ($q) => $q->whereNull('scope'))
            ->when($scope !== null, fn ($q) => $q->where('scope', json_encode($scope)))
            ->first();

        if ($existing) {
            $existing->update([
                'expected'    => $expected,
                'actual'      => $actual,
                'variance'    => $variance,
                'detail'      => $detail,
                'detected_at' => Carbon::now(),
            ]);

            return $existing;
        }

        return LedgerDriftFinding::create([
            'check_type'   => $checkType,
            'source_type'  => $sourceType,
            'period_start' => $from?->toDateString(),
            'period_end'   => $to?->toDateString(),
            'scope'        => $scope,
            'expected'     => $expected,
            'actual'       => $actual,
            'variance'     => $variance,
            'detail'       => $detail,
            'detected_at'  => Carbon::now(),
        ]);
    }
}
