<?php

namespace App\Reports\Definitions;

use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Stock at or below its reorder threshold.
 *
 * The threshold is the per-location inventory.reorder_level, falling back to
 * the global products.recommended_stocks when the location has not set one.
 * The old reports/low-stock-report closure compared against the global value
 * ONLY, which made per-location shortfalls invisible. The row names which
 * threshold applied so a flagged line is never mysterious.
 */
class LowStockReport implements ReportDefinition
{
    public function key(): string
    {
        return 'low-stock';
    }

    public function title(): string
    {
        return 'Low Stock Report';
    }

    public function description(): string
    {
        return 'Items at or below their reorder level';
    }

    public function parameters(): array
    {
        return [
            new ReportParameter('location_id', ReportParameter::TYPE_LOCATION, 'Location'),
            new ReportParameter('category_id', ReportParameter::TYPE_CATEGORY, 'Category'),
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

    public function build(ReportParameters $params, User $user): ReportDataset
    {
        $rows = app(\App\Services\Analytics\StockSnapshotService::class)
            ->forUser($user, $params->int('location_id'), $params->int('category_id'))
            ->filter(fn ($row) => in_array($row['status'], ['critical', 'low'], true))
            ->sortByDesc('shortfall')->values()->all();

        return new ReportDataset(
            columns: [
                'product'          => 'Product',
                'product_code'     => 'Code',
                'category'         => 'Category',
                'location'         => 'Location',
                'quantity_on_hand' => 'On Hand',
                'threshold'        => 'Threshold',
                'threshold_source' => 'Threshold From',
                'shortfall'        => 'Shortfall',
            ],
            rows: $rows,
            summary: [
                'Items below threshold' => number_format(count($rows)),
                'Total shortfall units' => number_format(array_sum(array_column($rows, 'shortfall'))),
            ],
            notes: ['Threshold is the location reorder level where set, otherwise the product default. Out-of-stock lines are included even when no positive threshold is configured.'],
            // Rows here were already raw ints, so this is not fixing a bug —
            // it just declares the PDF display format explicitly rather than
            // relying on the blade's default of "print the value as-is",
            // so a large shortfall still reads with a thousands separator.
            columnFormats: [
                'quantity_on_hand' => ReportDataset::FORMAT_INTEGER,
                'threshold'        => ReportDataset::FORMAT_INTEGER,
                'shortfall'        => ReportDataset::FORMAT_INTEGER,
            ],
        );
    }
}
