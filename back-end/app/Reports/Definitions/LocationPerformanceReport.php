<?php

namespace App\Reports\Definitions;

use App\Models\Location;
use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;
use Illuminate\Support\Facades\DB;

/**
 * Location-wise movement and valuation, one row per location.
 *
 * There is no `material_movement_ledger` table (see the 2026-08-17 design
 * correction) — movement is read from the same five real tables
 * TransactionReport uses. Unlike TransactionReport, which lists one row per
 * *document*, this report lists one row per *location*, so the movement
 * figures are GROUP BY aggregates rather than enumerated rows. That is a
 * genuinely different query shape (aggregate sums keyed by location_id vs.
 * per-document Eloquent collections), not just a different filter, so the
 * two definitions do not share query code: a shared helper would have to be
 * parameterized over "group by this column, sum that column, across five
 * tables with different column names" for only two call sites, which is
 * more machinery than the duplication it would save. What IS shared is the
 * choice of source tables/columns and their date columns — that shared
 * understanding lives in the design doc and in each definition's own
 * doc-comment, which is the cheaper form of sharing here.
 *
 * `transfers` has two distinct quantity columns because a transfer can be
 * short-received: `total_quantity_transferred` is what left the source,
 * `total_quantity_received` is what actually arrived at the destination.
 * This report uses the column that matches each side's real effect on that
 * location's stock — the source location lost what it sent (transferred),
 * the destination location gained what it actually got (received) — rather
 * than using one column for both sides, which would silently assume no
 * transfer is ever short.
 *
 * Closing stock/valuation is a live snapshot (from `inventory` joined to
 * `products.cost_price`), not as of the range end — there is no historical
 * stock table to reconstruct an as-of figure from. The dataset notes this
 * explicitly.
 *
 * Scoping is applied to the LOCATION list itself, once, rather than to each
 * of the five movement queries independently: a scoped user's visible
 * locations are resolved first (empty for a scoped user with no location,
 * per ScopesToUserLocation's rule), and every aggregate query below is then
 * restricted to that already-scoped set of location ids. This is simpler
 * and strictly equivalent to scoping each movement query, because every
 * movement query here is filtered by a location_id column it already has to
 * filter for grouping.
 */
class LocationPerformanceReport implements ReportDefinition
{
    public function key(): string
    {
        return 'location-performance';
    }

    public function title(): string
    {
        return 'Location Performance';
    }

    public function description(): string
    {
        return 'Location-wise inventory movement and valuation';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Date Range', required: true),
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('location_type', ReportParameter::TYPE_LOCATION_TYPE, 'Location Type'),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 2000;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        // Same reasoning as TransactionReport: compare against DATE columns
        // using plain date strings, since dateTo() is endOfDay().
        $fromDate = $params->dateFrom()?->toDateString();
        $toDate   = $params->dateTo()?->toDateString();

        $locations = $this->scopedLocations($user, $params);
        $locationIds = $locations->pluck('location_id')->map(fn ($id) => (int) $id)->all();

        if ($locationIds === []) {
            return new ReportDataset(
                columns: $this->columns(),
                rows: [],
                notes: [$this->closingStockNote()],
                columnFormats: $this->columnFormats(),
            );
        }

        $unitsIn        = array_fill_keys($locationIds, 0);
        $unitsOut       = array_fill_keys($locationIds, 0);
        $netAdjustments = array_fill_keys($locationIds, 0);
        $closingUnits   = array_fill_keys($locationIds, 0);
        $closingValue   = array_fill_keys($locationIds, 0.0);

        // Units in: receivings at the location.
        $this->applyDateFilter(
            DB::table('receivings')->whereIn('location_id', $locationIds),
            'receiving_date', $fromDate, $toDate
        )->select('location_id', DB::raw('SUM(total_quantity_received) as qty'))
            ->groupBy('location_id')
            ->get()
            ->each(function ($row) use (&$unitsIn) {
                $unitsIn[(int) $row->location_id] += (int) $row->qty;
            });

        // Units in: transfers received AT the location (what actually
        // arrived, not what was sent — see class doc-comment).
        $this->applyDateFilter(
            DB::table('transfers')->whereIn('to_location_id', $locationIds),
            'transfer_date', $fromDate, $toDate
        )->select('to_location_id', DB::raw('SUM(total_quantity_received) as qty'))
            ->groupBy('to_location_id')
            ->get()
            ->each(function ($row) use (&$unitsIn) {
                $unitsIn[(int) $row->to_location_id] += (int) $row->qty;
            });

        // Units out: issuances from the location.
        $this->applyDateFilter(
            DB::table('issuance_details')
                ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
                ->whereIn('issuances.location_id', $locationIds),
            'issuances.issuance_date', $fromDate, $toDate
        )->select('issuances.location_id as location_id', DB::raw('SUM(issuance_details.quantity_issued) as qty'))
            ->groupBy('issuances.location_id')
            ->get()
            ->each(function ($row) use (&$unitsOut) {
                $unitsOut[(int) $row->location_id] += (int) $row->qty;
            });

        // Units out: sales from the location.
        $this->applyDateFilter(
            DB::table('sales_details')
                ->join('sales', 'sales.sales_id', '=', 'sales_details.sales_id')
                ->whereIn('sales.location_id', $locationIds),
            'sales.sale_date', $fromDate, $toDate
        )->select('sales.location_id as location_id', DB::raw('SUM(sales_details.quantity) as qty'))
            ->groupBy('sales.location_id')
            ->get()
            ->each(function ($row) use (&$unitsOut) {
                $unitsOut[(int) $row->location_id] += (int) $row->qty;
            });

        // Units out: transfers sent FROM the location (what left, not what
        // was received at the other end — see class doc-comment).
        $this->applyDateFilter(
            DB::table('transfers')->whereIn('from_location_id', $locationIds),
            'transfer_date', $fromDate, $toDate
        )->select('from_location_id', DB::raw('SUM(total_quantity_transferred) as qty'))
            ->groupBy('from_location_id')
            ->get()
            ->each(function ($row) use (&$unitsOut) {
                $unitsOut[(int) $row->from_location_id] += (int) $row->qty;
            });

        // Net adjustments: reported in its own column, never folded into
        // units in/out — a count correction is not goods moving.
        $this->applyDateFilter(
            DB::table('adjustments')->whereIn('location_id', $locationIds),
            'adjustment_date', $fromDate, $toDate
        )->select(
            'location_id',
            DB::raw('SUM(total_variance_positive) as pos'),
            DB::raw('SUM(total_variance_negative) as neg')
        )->groupBy('location_id')
            ->get()
            ->each(function ($row) use (&$netAdjustments) {
                $netAdjustments[(int) $row->location_id] += (int) $row->pos - (int) $row->neg;
            });

        // Closing stock/valuation: a live snapshot, not as of the range end.
        DB::table('inventory')
            ->join('products', 'products.product_id', '=', 'inventory.product_id')
            ->whereIn('inventory.location_id', $locationIds)
            ->select(
                'inventory.location_id as location_id',
                DB::raw('SUM(inventory.quantity_on_hand) as qty'),
                DB::raw('SUM(inventory.quantity_on_hand * products.cost_price) as value')
            )->groupBy('inventory.location_id')
            ->get()
            ->each(function ($row) use (&$closingUnits, &$closingValue) {
                $closingUnits[(int) $row->location_id] += (int) $row->qty;
                $closingValue[(int) $row->location_id] += (float) $row->value;
            });

        $rows = [];
        $totalUnitsIn = 0;
        $totalUnitsOut = 0;
        $totalClosingValue = 0.0;

        foreach ($locations as $location) {
            $id = (int) $location->location_id;

            $rows[] = [
                'location'          => $location->location_name,
                'location_type'     => $location->location_type,
                'units_in'          => $unitsIn[$id],
                'units_out'         => $unitsOut[$id],
                'net_adjustments'   => $netAdjustments[$id],
                'closing_units'     => $closingUnits[$id],
                'closing_valuation' => round($closingValue[$id], 2),
            ];

            $totalUnitsIn += $unitsIn[$id];
            $totalUnitsOut += $unitsOut[$id];
            $totalClosingValue += $closingValue[$id];
        }

        usort($rows, fn ($a, $b) => $a['location'] <=> $b['location']);

        return new ReportDataset(
            columns: $this->columns(),
            rows: $rows,
            summary: [
                'Locations'               => number_format(count($rows)),
                'Total units in'          => number_format($totalUnitsIn),
                'Total units out'         => number_format($totalUnitsOut),
                'Total closing valuation' => number_format($totalClosingValue, 2),
            ],
            notes: [$this->closingStockNote()],
            columnFormats: $this->columnFormats(),
        );
    }

    /**
     * Resolve the locations this user/these filters may see, applying
     * ScopesToUserLocation's rule directly to the Location list itself
     * rather than scoping each of the five movement queries independently
     * (see class doc-comment).
     */
    private function scopedLocations(User $user, ReportParameters $params)
    {
        $query = Location::query();

        if (! $user->isCentralized()) {
            $scopedLocationId = $user->scopedLocationId();
            if ($scopedLocationId === null) {
                return $query->whereRaw('1 = 0')->get();
            }
            $query->where('location_id', $scopedLocationId);
        }

        if ($params->has('location_id')) {
            $query->where('location_id', $params->int('location_id'));
        }

        if ($params->has('location_type')) {
            $query->where('location_type', $params->get('location_type'));
        }

        return $query->get();
    }

    private function applyDateFilter($query, string $column, ?string $fromDate, ?string $toDate)
    {
        if ($fromDate !== null) {
            $query->where($column, '>=', $fromDate);
        }
        if ($toDate !== null) {
            $query->where($column, '<=', $toDate);
        }

        return $query;
    }

    private function closingStockNote(): string
    {
        return 'Closing stock and valuation are current as of the generation time, not as of the end of the selected date range — there is no historical stock table.';
    }

    private function columns(): array
    {
        return [
            'location'          => 'Location',
            'location_type'     => 'Type',
            'units_in'          => 'Units In',
            'units_out'         => 'Units Out',
            'net_adjustments'   => 'Net Adjustments',
            'closing_units'     => 'Closing Units',
            'closing_valuation' => 'Closing Valuation',
        ];
    }

    private function columnFormats(): array
    {
        return [
            'units_in'          => ReportDataset::FORMAT_INTEGER,
            'units_out'         => ReportDataset::FORMAT_INTEGER,
            'net_adjustments'   => ReportDataset::FORMAT_INTEGER,
            'closing_units'     => ReportDataset::FORMAT_INTEGER,
            'closing_valuation' => ReportDataset::FORMAT_MONEY,
        ];
    }
}
