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
 * How much of a warehouse's space is in use, and how hard that space worked
 * over a period — one row per location, mixing a snapshot measure with
 * period measures.
 *
 * **Corrected 2026-08-17.** The original spec read Sections C and D from a
 * `material_movement_ledger` table. That table does not exist — see the
 * design doc's Warehouse Utilization section. Every movement here is read
 * from its own real table instead (receivings, transfers, issuance_details,
 * sales_details), the same sources LocationPerformanceReport uses. Because
 * each source is unambiguously inbound or outbound and lives in its own
 * table, double-counting is not a hazard and no exclusion guard is needed —
 * unlike the ledger design, which would have needed one to keep a receiving
 * from appearing twice.
 *
 * **Occupancy is a snapshot; receiving, transfers, and dispatch are period
 * figures.** Section A reads `inventory.quantity_on_hand` as of generation
 * time — there is no historical stock table to reconstruct an as-of-range-end
 * figure from. Sections B-D are filtered by the selected date range. The
 * dataset's notes state both framings explicitly so a utilization percentage
 * is never misread as an average over the period.
 *
 * **Null capacity is this report's signature correctness requirement.** A
 * location nobody has configured a capacity for cannot have a utilization
 * percentage, a remaining capacity, or a throughput ratio computed against
 * it — doing so would either divide by zero or silently print a misleading
 * 0%. Those columns render the literal string 'Not set' for such a location
 * instead, and the location is excluded from every aggregate percentage/ratio
 * in the summary (but still contributes its raw counts, e.g. units handled).
 * `ReportDataset::formatValue()`'s non-numeric passthrough exists specifically
 * so this sentinel is never coerced into "0.00" by the PDF renderer.
 *
 * Percentages and ratios (utilization %, damage rate, on-time %, throughput
 * ratio) are pre-formatted strings computed here, not raw floats run through
 * `columnFormats` — there is no FORMAT_PERCENT in the deliberately small
 * closed set on ReportDataset, and adding one is out of this file's scope.
 * Whole-number columns (capacity, occupied units, receipt/unit counts,
 * transfer/dispatch units) stay raw ints with FORMAT_INTEGER declared, per
 * the project-wide "rows carry raw numbers" rule.
 *
 * **CSV vs PDF shape.** `rows` is one flat row per location with every
 * metric as its own column — the shape CSV needs, and the canonical data
 * `ReportDataset` requires to be non-empty whenever `sections` is used.
 * `sections` re-slices those same rows into five focused tables (space,
 * receiving, transfers, goods out, throughput) for the PDF, which reads
 * naturally as stacked tables rather than one very wide one.
 *
 * Scoping is applied to the location list itself, once — see
 * LocationPerformanceReport's doc-comment for why that is simpler than
 * scoping each movement query independently. `location_type` defaults to
 * `warehouse` when the caller does not supply one, because the report keeps
 * its warehouse-operations-specific name and content while still allowing
 * the filter to target another type on request.
 */
class WarehouseUtilizationReport implements ReportDefinition
{
    public function key(): string
    {
        return 'warehouse-utilization';
    }

    public function title(): string
    {
        return 'Warehouse Utilization';
    }

    public function description(): string
    {
        return 'Capacity, occupancy, and goods throughput';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('date_range', ReportParameter::TYPE_DATE_RANGE, 'Date Range', required: true),
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('location_type', ReportParameter::TYPE_LOCATION_TYPE, 'Location Type', default: 'warehouse'),
        ];
    }

    public function formats(): array
    {
        return ['pdf', 'csv'];
    }

    public function pdfRowCap(): int
    {
        return 500;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        // Compare against DATE columns using plain date strings — same
        // reasoning as TransactionReport/LocationPerformanceReport: dateTo()
        // is endOfDay(), and this keeps the comparison exact.
        $fromDate = $params->dateFrom()?->toDateString();
        $toDate   = $params->dateTo()?->toDateString();

        $locations = $this->scopedLocations($user, $params);
        $locationIds = $locations->pluck('location_id')->map(fn ($id) => (int) $id)->all();

        if ($locationIds === []) {
            return new ReportDataset(
                columns: $this->columns(),
                rows: [],
                notes: [$this->framingNote()],
                columnFormats: $this->columnFormats(),
            );
        }

        // --- Section A data: occupancy, a live snapshot -------------------
        $occupied = array_fill_keys($locationIds, 0);
        DB::table('inventory')
            ->whereIn('location_id', $locationIds)
            ->select('location_id', DB::raw('SUM(quantity_on_hand) as qty'))
            ->groupBy('location_id')
            ->get()
            ->each(function ($row) use (&$occupied) {
                $occupied[(int) $row->location_id] += (int) $row->qty;
            });

        // --- Section B data: receivings against POs, period ---------------
        $receivingCount = array_fill_keys($locationIds, 0);
        $poIdsByLocation = array_fill_keys($locationIds, []);
        $unitsReceived   = array_fill_keys($locationIds, 0);
        $unitsDamaged    = array_fill_keys($locationIds, 0);
        $onTimeCount     = array_fill_keys($locationIds, 0);
        $lateCount       = array_fill_keys($locationIds, 0);
        $unratedCount    = array_fill_keys($locationIds, 0);

        $this->applyDateFilter(
            DB::table('receivings')
                ->leftJoin('purchase_orders', 'purchase_orders.po_id', '=', 'receivings.po_id')
                ->whereIn('receivings.location_id', $locationIds),
            'receivings.receiving_date', $fromDate, $toDate
        )->select(
            'receivings.location_id as location_id',
            'receivings.po_id as po_id',
            'receivings.receiving_date as receiving_date',
            'receivings.total_quantity_received as units_received',
            'receivings.total_quantity_damaged as units_damaged',
            'purchase_orders.expected_delivery_date as expected_delivery_date'
        )->get()
            ->each(function ($row) use (
                &$receivingCount, &$poIdsByLocation, &$unitsReceived, &$unitsDamaged,
                &$onTimeCount, &$lateCount, &$unratedCount
            ) {
                $locationId = (int) $row->location_id;

                $receivingCount[$locationId]++;
                $unitsReceived[$locationId] += (int) $row->units_received;
                $unitsDamaged[$locationId]  += (int) $row->units_damaged;

                if ($row->po_id !== null) {
                    $poIdsByLocation[$locationId][] = (int) $row->po_id;
                }

                // A receipt whose PO has no expected delivery date cannot be
                // judged on-time vs late — excluded from that percentage,
                // counted here instead (see class doc-comment).
                if ($row->expected_delivery_date === null) {
                    $unratedCount[$locationId]++;
                } elseif ($row->receiving_date <= $row->expected_delivery_date) {
                    $onTimeCount[$locationId]++;
                } else {
                    $lateCount[$locationId]++;
                }
            });

        // --- Section C data: transfers, period -----------------------------
        $transfersIn  = array_fill_keys($locationIds, 0);
        $transfersOut = array_fill_keys($locationIds, 0);

        $this->applyDateFilter(
            DB::table('transfers')->whereIn('to_location_id', $locationIds),
            'transfer_date', $fromDate, $toDate
        )->select('to_location_id as location_id', DB::raw('SUM(total_quantity_received) as qty'))
            ->groupBy('to_location_id')
            ->get()
            ->each(function ($row) use (&$transfersIn) {
                $transfersIn[(int) $row->location_id] += (int) $row->qty;
            });

        $this->applyDateFilter(
            DB::table('transfers')->whereIn('from_location_id', $locationIds),
            'transfer_date', $fromDate, $toDate
        )->select('from_location_id as location_id', DB::raw('SUM(total_quantity_transferred) as qty'))
            ->groupBy('from_location_id')
            ->get()
            ->each(function ($row) use (&$transfersOut) {
                $transfersOut[(int) $row->location_id] += (int) $row->qty;
            });

        // --- Section D data: goods out, period ------------------------------
        $goodsOutIssuance = array_fill_keys($locationIds, 0);
        $goodsOutSales    = array_fill_keys($locationIds, 0);

        $this->applyDateFilter(
            DB::table('issuance_details')
                ->join('issuances', 'issuances.issuance_id', '=', 'issuance_details.issuance_id')
                ->whereIn('issuances.location_id', $locationIds),
            'issuances.issuance_date', $fromDate, $toDate
        )->select('issuances.location_id as location_id', DB::raw('SUM(issuance_details.quantity_issued) as qty'))
            ->groupBy('issuances.location_id')
            ->get()
            ->each(function ($row) use (&$goodsOutIssuance) {
                $goodsOutIssuance[(int) $row->location_id] += (int) $row->qty;
            });

        $this->applyDateFilter(
            DB::table('sales_details')
                ->join('sales', 'sales.sales_id', '=', 'sales_details.sales_id')
                ->whereIn('sales.location_id', $locationIds),
            'sales.sale_date', $fromDate, $toDate
        )->select('sales.location_id as location_id', DB::raw('SUM(sales_details.quantity) as qty'))
            ->groupBy('sales.location_id')
            ->get()
            ->each(function ($row) use (&$goodsOutSales) {
                $goodsOutSales[(int) $row->location_id] += (int) $row->qty;
            });

        // --- Assemble one flat row per location -----------------------------
        $rows = [];

        $sumCapacityForAggregate = 0;
        $sumOccupiedForAggregate = 0;
        $sumUnitsHandledForAggregate = 0;
        $locationsWithoutCapacity = 0;

        $totalOccupied = 0;
        $totalReceivingCount = 0;
        $totalPoCount = 0;
        $totalUnitsReceived = 0;
        $totalUnitsDamaged = 0;
        $totalOnTime = 0;
        $totalLate = 0;
        $totalUnrated = 0;
        $totalTransfersIn = 0;
        $totalTransfersOut = 0;
        $totalGoodsOutIssuance = 0;
        $totalGoodsOutSales = 0;
        $totalUnitsHandled = 0;

        foreach ($locations as $location) {
            $id = (int) $location->location_id;
            $capacity = $location->capacity === null ? null : (int) $location->capacity;

            $occupiedUnits = $occupied[$id];
            $poCount = count(array_unique($poIdsByLocation[$id]));
            $goodsOutTotal = $goodsOutIssuance[$id] + $goodsOutSales[$id];

            // Throughput excludes count adjustments entirely — a correction
            // is not goods moving. There is no Return-to-Vendor line.
            $unitsHandled = $unitsReceived[$id] + $transfersIn[$id] + $transfersOut[$id] + $goodsOutTotal;

            if ($capacity === null) {
                $capacityCell = 'Not set';
                $utilizationCell = 'Not set';
                $remainingCell = 'Not set';
                $throughputCell = 'Not set';
                $locationsWithoutCapacity++;
            } else {
                $capacityCell = $capacity;
                $utilizationCell = $this->formatPercent($occupiedUnits, $capacity);
                $remainingCell = $capacity - $occupiedUnits;
                $throughputCell = $this->formatRatio($unitsHandled, $capacity);

                $sumCapacityForAggregate += $capacity;
                $sumOccupiedForAggregate += $occupiedUnits;
                $sumUnitsHandledForAggregate += $unitsHandled;
            }

            $rows[] = [
                'location'           => $location->location_name,
                'location_type'      => $location->location_type,
                'capacity'           => $capacityCell,
                'occupied_units'     => $occupiedUnits,
                'utilization_pct'    => $utilizationCell,
                'remaining_capacity' => $remainingCell,
                'receiving_count'    => $receivingCount[$id],
                'po_count'           => $poCount,
                'units_received'     => $unitsReceived[$id],
                'units_damaged'      => $unitsDamaged[$id],
                'damage_rate'        => $this->formatPercent($unitsDamaged[$id], $unitsReceived[$id]),
                'on_time_count'      => $onTimeCount[$id],
                'late_count'         => $lateCount[$id],
                'unrated_count'      => $unratedCount[$id],
                'on_time_pct'        => $this->formatPercent($onTimeCount[$id], $onTimeCount[$id] + $lateCount[$id]),
                'transfers_in'       => $transfersIn[$id],
                'transfers_out'      => $transfersOut[$id],
                'goods_out_issuance' => $goodsOutIssuance[$id],
                'goods_out_sales'    => $goodsOutSales[$id],
                'goods_out_total'    => $goodsOutTotal,
                'units_handled'      => $unitsHandled,
                'throughput_ratio'   => $throughputCell,
            ];

            $totalOccupied         += $occupiedUnits;
            $totalReceivingCount   += $receivingCount[$id];
            $totalPoCount          += $poCount;
            $totalUnitsReceived    += $unitsReceived[$id];
            $totalUnitsDamaged     += $unitsDamaged[$id];
            $totalOnTime           += $onTimeCount[$id];
            $totalLate             += $lateCount[$id];
            $totalUnrated          += $unratedCount[$id];
            $totalTransfersIn      += $transfersIn[$id];
            $totalTransfersOut     += $transfersOut[$id];
            $totalGoodsOutIssuance += $goodsOutIssuance[$id];
            $totalGoodsOutSales    += $goodsOutSales[$id];
            $totalUnitsHandled     += $unitsHandled;
        }

        usort($rows, fn ($a, $b) => $a['location'] <=> $b['location']);

        $summary = [
            'Locations'                    => number_format(count($rows)),
            'Locations without a capacity' => number_format($locationsWithoutCapacity),
            'Total occupied units'         => number_format($totalOccupied),
            'Aggregate utilization %'      => $this->formatPercent($sumOccupiedForAggregate, $sumCapacityForAggregate),
            'Total receipts'               => number_format($totalReceivingCount),
            'Distinct POs received'        => number_format($totalPoCount),
            'Total units received'         => number_format($totalUnitsReceived),
            'Total units damaged'          => number_format($totalUnitsDamaged),
            'Overall damage rate'          => $this->formatPercent($totalUnitsDamaged, $totalUnitsReceived),
            'Overall on-time %'            => $this->formatPercent($totalOnTime, $totalOnTime + $totalLate),
            'Receipts excluded (no expected delivery date)' => number_format($totalUnrated),
            'Total transfers in'           => number_format($totalTransfersIn),
            'Total transfers out'          => number_format($totalTransfersOut),
            'Total units issued'           => number_format($totalGoodsOutIssuance),
            'Total units sold'             => number_format($totalGoodsOutSales),
            'Total units handled (throughput)' => number_format($totalUnitsHandled),
            'Aggregate throughput ratio'   => $this->formatRatio($sumUnitsHandledForAggregate, $sumCapacityForAggregate),
        ];

        $notes = [$this->framingNote()];
        if ($locationsWithoutCapacity > 0) {
            $notes[] = "{$locationsWithoutCapacity} location(s) have no configured capacity and render as 'Not set' for capacity, utilization, remaining capacity, and throughput ratio; they are excluded from the aggregate utilization and throughput figures above.";
        }
        if ($totalUnrated > 0) {
            $notes[] = "{$totalUnrated} receipt(s) could not be judged on-time vs late because their purchase order has no expected delivery date; they are excluded from the on-time percentage and counted separately.";
        }

        return new ReportDataset(
            columns: $this->columns(),
            rows: $rows,
            summary: $summary,
            sections: $this->sections($rows),
            notes: $notes,
            columnFormats: $this->columnFormats(),
        );
    }

    /**
     * Resolve the locations this user/these filters may see, applying
     * ScopesToUserLocation's rule to the location list itself (see
     * LocationPerformanceReport's doc-comment). `location_type` defaults to
     * `warehouse` when not supplied, per this report's design.
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

        $locationType = $params->has('location_type') ? $params->get('location_type') : 'warehouse';
        $query->where('location_type', $locationType);

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

    /**
     * A percentage as a display-ready string, or the literal 'Not set' when
     * the denominator is zero — never a division by zero, never a misleading
     * 0%. Used for utilization, damage rate, and on-time percentage alike.
     */
    private function formatPercent(float $numerator, float $denominator): string
    {
        if ($denominator <= 0.0) {
            return 'Not set';
        }

        return number_format(($numerator / $denominator) * 100, 1) . '%';
    }

    /**
     * Units handled per unit of capacity — the throughput ratio — as a
     * display-ready string, or 'Not set' when capacity is zero/unset.
     */
    private function formatRatio(float $numerator, float $denominator): string
    {
        if ($denominator <= 0.0) {
            return 'Not set';
        }

        return number_format($numerator / $denominator, 2);
    }

    private function framingNote(): string
    {
        return "Occupancy (Space) reflects stock as of the report's generation time, not the selected date range — there is no historical stock table. Receiving, Transfers, and Goods Out figures are totals for the selected period.";
    }

    /**
     * Re-slice the flat per-location rows into five focused PDF tables. Each
     * section reuses the exact same row arrays (just a different column
     * selection), so `PdfReportRenderer` applies the one columnFormats map
     * to all of them uniformly.
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    private function sections(array $rows): array
    {
        return [
            [
                'title'   => 'Space Utilization (as of report generation)',
                'columns' => [
                    'location'           => 'Location',
                    'location_type'      => 'Type',
                    'capacity'           => 'Capacity',
                    'occupied_units'     => 'Occupied Units',
                    'utilization_pct'    => 'Utilization %',
                    'remaining_capacity' => 'Remaining Capacity',
                ],
                'rows' => $rows,
            ],
            [
                'title'   => 'Goods Received Against Purchase Orders (period)',
                'columns' => [
                    'location'        => 'Location',
                    'receiving_count' => 'Receipts',
                    'po_count'        => 'Distinct POs',
                    'units_received'  => 'Units Received',
                    'units_damaged'   => 'Units Damaged',
                    'damage_rate'     => 'Damage Rate',
                    'on_time_count'   => 'On-Time',
                    'late_count'      => 'Late',
                    'unrated_count'   => 'Unrated',
                    'on_time_pct'     => 'On-Time %',
                ],
                'rows' => $rows,
            ],
            [
                'title'   => 'Transfers (period)',
                'columns' => [
                    'location'      => 'Location',
                    'transfers_in'  => 'Transfers In',
                    'transfers_out' => 'Transfers Out',
                ],
                'rows' => $rows,
            ],
            [
                'title'   => 'Goods Out (period)',
                'columns' => [
                    'location'           => 'Location',
                    'goods_out_issuance' => 'Issued',
                    'goods_out_sales'    => 'Sold',
                    'goods_out_total'    => 'Total Dispatched',
                ],
                'rows' => $rows,
            ],
            [
                'title'   => 'Throughput (period vs. capacity)',
                'columns' => [
                    'location'         => 'Location',
                    'units_handled'    => 'Units Handled',
                    'throughput_ratio' => 'Throughput Ratio',
                ],
                'rows' => $rows,
            ],
        ];
    }

    private function columns(): array
    {
        return [
            'location'           => 'Location',
            'location_type'      => 'Type',
            'capacity'           => 'Capacity',
            'occupied_units'     => 'Occupied Units',
            'utilization_pct'    => 'Utilization %',
            'remaining_capacity' => 'Remaining Capacity',
            'receiving_count'    => 'Receipts',
            'po_count'           => 'Distinct POs',
            'units_received'     => 'Units Received',
            'units_damaged'      => 'Units Damaged',
            'damage_rate'        => 'Damage Rate',
            'on_time_count'      => 'On-Time Receipts',
            'late_count'         => 'Late Receipts',
            'unrated_count'      => 'Unrated Receipts',
            'on_time_pct'        => 'On-Time %',
            'transfers_in'       => 'Transfers In',
            'transfers_out'      => 'Transfers Out',
            'goods_out_issuance' => 'Issued',
            'goods_out_sales'    => 'Sold',
            'goods_out_total'    => 'Total Dispatched',
            'units_handled'      => 'Units Handled',
            'throughput_ratio'   => 'Throughput Ratio',
        ];
    }

    private function columnFormats(): array
    {
        return [
            'capacity'           => ReportDataset::FORMAT_INTEGER,
            'occupied_units'     => ReportDataset::FORMAT_INTEGER,
            'remaining_capacity' => ReportDataset::FORMAT_INTEGER,
            'receiving_count'    => ReportDataset::FORMAT_INTEGER,
            'po_count'           => ReportDataset::FORMAT_INTEGER,
            'units_received'     => ReportDataset::FORMAT_INTEGER,
            'units_damaged'      => ReportDataset::FORMAT_INTEGER,
            'on_time_count'      => ReportDataset::FORMAT_INTEGER,
            'late_count'         => ReportDataset::FORMAT_INTEGER,
            'unrated_count'      => ReportDataset::FORMAT_INTEGER,
            'transfers_in'       => ReportDataset::FORMAT_INTEGER,
            'transfers_out'      => ReportDataset::FORMAT_INTEGER,
            'goods_out_issuance' => ReportDataset::FORMAT_INTEGER,
            'goods_out_sales'    => ReportDataset::FORMAT_INTEGER,
            'goods_out_total'    => ReportDataset::FORMAT_INTEGER,
            'units_handled'      => ReportDataset::FORMAT_INTEGER,
        ];
    }
}
