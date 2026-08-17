<?php

namespace App\Reports\Definitions;

use App\Models\Adjustment;
use App\Models\Issuance;
use App\Models\Receiving;
use App\Models\Sale;
use App\Models\Transfer;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Every recorded inventory movement for a period, one row per document.
 *
 * There is no `transactions` table (see the 2026-08-17 design correction) —
 * this reads the same five real tables the Transactions page composes its
 * view from: receivings, issuances, transfers, adjustments, and sales. Rows
 * are document-level rather than line-level because the five sources have
 * genuinely incompatible line shapes (quantity_issued, quantity_transferred,
 * variance positive/negative, quantity) — a line-level union would have to
 * invent a common meaning for columns that differ.
 *
 * `issuances` has no reference-number column (unlike receivings, transfers,
 * adjustments, and sales, which all have one) — the reference is built from
 * the issuance_id with a readable "ISS-" prefix instead.
 */
class TransactionReport implements ReportDefinition
{
    private const TYPE_RECEIVING  = 'receiving';
    private const TYPE_ISSUANCE   = 'issuance';
    private const TYPE_TRANSFER   = 'transfer';
    private const TYPE_ADJUSTMENT = 'adjustment';
    private const TYPE_SALE       = 'sale';

    private const TYPE_LABELS = [
        self::TYPE_RECEIVING  => 'Receiving',
        self::TYPE_ISSUANCE   => 'Issuance',
        self::TYPE_TRANSFER   => 'Transfer',
        self::TYPE_ADJUSTMENT => 'Adjustment',
        self::TYPE_SALE       => 'Sale',
    ];

    public function key(): string
    {
        return 'transaction';
    }

    public function title(): string
    {
        return 'Transaction Report';
    }

    public function description(): string
    {
        return 'All recorded inventory movements for a period';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Date Range', required: true),
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('movement_type', ReportParameter::TYPE_SELECT, 'Movement Type', options: self::TYPE_LABELS),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 5000;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    /**
     * Apply an inclusive [from, to] filter on a source's own date column.
     * Both bounds are plain 'Y-m-d' strings (see build()'s note on why),
     * and either may be null if that bound wasn't supplied.
     */
    private function applyDateRange($query, string $dateColumn, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->where($dateColumn, '>=', $from);
        }
        if ($to !== null) {
            $query->where($dateColumn, '<=', $to);
        }
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        // Compare against DATE columns using plain date strings, not
        // datetime strings with a time component (dateTo() is endOfDay()) —
        // MySQL implicitly casts a DATE column when compared to a datetime
        // string so it would still work, but this keeps the comparison
        // exact and avoids relying on that implicit cast.
        $fromDate = $params->dateFrom()?->toDateString();
        $toDate   = $params->dateTo()?->toDateString();
        $locationId = $params->int('location_id');
        $movementType = $params->has('movement_type') ? $params->get('movement_type') : null;

        $rows = [];
        $docCounts = array_fill_keys(self::TYPE_LABELS, 0);
        $unitTotals = array_fill_keys(self::TYPE_LABELS, 0);
        $totalSaleValue = 0.0;

        $wantsType = fn (string $type): bool => $movementType === null || $movementType === $type;

        if ($wantsType(self::TYPE_RECEIVING)) {
            $query = Receiving::query()->forUser($user)->with('location');
            $this->applyDateRange($query, 'receiving_date', $fromDate, $toDate);
            if ($locationId !== null) {
                $query->where('location_id', $locationId);
            }

            foreach ($query->get() as $receiving) {
                $units = (int) $receiving->total_quantity_received;
                $label = self::TYPE_LABELS[self::TYPE_RECEIVING];

                $rows[] = [
                    'date'      => $receiving->receiving_date?->format('Y-m-d'),
                    'reference' => $receiving->receiving_number,
                    'type'      => $label,
                    'location'  => $receiving->location?->location_name ?? '—',
                    'units'     => $units,
                    'value'     => null,
                ];

                $docCounts[$label]++;
                $unitTotals[$label] += $units;
            }
        }

        if ($wantsType(self::TYPE_ISSUANCE)) {
            $query = Issuance::query()->forUser($user)->with('location')->withSum('details', 'quantity_issued');
            $this->applyDateRange($query, 'issuance_date', $fromDate, $toDate);
            if ($locationId !== null) {
                $query->where('location_id', $locationId);
            }

            foreach ($query->get() as $issuance) {
                $units = (int) ($issuance->details_sum_quantity_issued ?? 0);
                $label = self::TYPE_LABELS[self::TYPE_ISSUANCE];

                $rows[] = [
                    'date'      => $issuance->issuance_date?->format('Y-m-d'),
                    // issuances has no reference-number column of its own.
                    'reference' => 'ISS-' . $issuance->issuance_id,
                    'type'      => $label,
                    'location'  => $issuance->location?->location_name ?? '—',
                    'units'     => $units,
                    'value'     => null,
                ];

                $docCounts[$label]++;
                $unitTotals[$label] += $units;
            }
        }

        if ($wantsType(self::TYPE_TRANSFER)) {
            $query = Transfer::query()->forUser($user)->with(['fromLocation', 'toLocation']);
            $this->applyDateRange($query, 'transfer_date', $fromDate, $toDate);
            if ($locationId !== null) {
                $query->where(function ($q) use ($locationId) {
                    $q->where('from_location_id', $locationId)
                      ->orWhere('to_location_id', $locationId);
                });
            }

            foreach ($query->get() as $transfer) {
                $units = (int) $transfer->total_quantity_transferred;
                $label = self::TYPE_LABELS[self::TYPE_TRANSFER];

                $rows[] = [
                    'date'      => $transfer->transfer_date?->format('Y-m-d'),
                    'reference' => $transfer->transfer_number,
                    'type'      => $label,
                    'location'  => ($transfer->fromLocation?->location_name ?? '—') . ' → ' . ($transfer->toLocation?->location_name ?? '—'),
                    'units'     => $units,
                    'value'     => null,
                ];

                $docCounts[$label]++;
                $unitTotals[$label] += $units;
            }
        }

        if ($wantsType(self::TYPE_ADJUSTMENT)) {
            $query = Adjustment::query()->forUser($user)->with('location');
            $this->applyDateRange($query, 'adjustment_date', $fromDate, $toDate);
            if ($locationId !== null) {
                $query->where('location_id', $locationId);
            }

            foreach ($query->get() as $adjustment) {
                $units = (int) $adjustment->total_variance_positive - (int) $adjustment->total_variance_negative;
                $label = self::TYPE_LABELS[self::TYPE_ADJUSTMENT];

                $rows[] = [
                    'date'      => $adjustment->adjustment_date?->format('Y-m-d'),
                    'reference' => $adjustment->adjustment_number,
                    'type'      => $label,
                    'location'  => $adjustment->location?->location_name ?? '—',
                    'units'     => $units,
                    'value'     => null,
                ];

                $docCounts[$label]++;
                $unitTotals[$label] += $units;
            }
        }

        if ($wantsType(self::TYPE_SALE)) {
            $query = Sale::query()->forUser($user)->with('location')->withSum('details', 'quantity');
            $this->applyDateRange($query, 'sale_date', $fromDate, $toDate);
            if ($locationId !== null) {
                $query->where('location_id', $locationId);
            }

            foreach ($query->get() as $sale) {
                $units = (int) ($sale->details_sum_quantity ?? 0);
                $value = round((float) $sale->total_amount, 2);
                $label = self::TYPE_LABELS[self::TYPE_SALE];

                $rows[] = [
                    'date'      => $sale->sale_date?->format('Y-m-d'),
                    'reference' => $sale->invoice_number,
                    'type'      => $label,
                    'location'  => $sale->location?->location_name ?? '—',
                    'units'     => $units,
                    'value'     => $value,
                ];

                $docCounts[$label]++;
                $unitTotals[$label] += $units;
                $totalSaleValue += $value;
            }
        }

        usort($rows, fn ($a, $b) => [$a['date'], $a['type'], $a['reference']] <=> [$b['date'], $b['type'], $b['reference']]);

        $summary = [];
        foreach (self::TYPE_LABELS as $label) {
            $summary["{$label} documents"] = number_format($docCounts[$label]);
            $summary["{$label} units"]     = number_format($unitTotals[$label]);
        }
        $summary['Total documents']  = number_format(array_sum($docCounts));
        $summary['Total units']      = number_format(array_sum($unitTotals));
        $summary['Total sale value'] = number_format($totalSaleValue, 2);

        return new ReportDataset(
            columns: [
                'date'      => 'Date',
                'reference' => 'Reference',
                'type'      => 'Type',
                'location'  => 'Location',
                'units'     => 'Units',
                'value'     => 'Value',
            ],
            rows: $rows,
            summary: $summary,
            notes: ['Transfers show origin → destination; every other type has a single location. Value is populated for sales only.'],
            columnFormats: [
                'units' => ReportDataset::FORMAT_INTEGER,
                'value' => ReportDataset::FORMAT_MONEY,
            ],
        );
    }
}
