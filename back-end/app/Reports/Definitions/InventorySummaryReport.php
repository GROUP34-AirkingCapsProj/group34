<?php

namespace App\Reports\Definitions;

use App\Models\User;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;

/**
 * Current stock and what it is worth, per product per location.
 *
 * Deliberately has no date range. There is no historical stock table, so an
 * "as of" filter could only be honoured by pretending — the document header
 * states plainly that it is a live snapshot.
 */
class InventorySummaryReport implements ReportDefinition
{
    public function key(): string
    {
        return 'inventory-summary';
    }

    public function title(): string
    {
        return 'Inventory Summary Report';
    }

    public function description(): string
    {
        return 'Current stock and valuation across all locations';
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
            ->forUser($user, $params->int('location_id'), $params->int('category_id'))->all();
        $totalUnits = array_sum(array_column($rows, 'quantity_on_hand'));
        $totalValue = array_sum(array_column($rows, 'stock_value'));

        return new ReportDataset(
            columns: [
                'product'            => 'Product',
                'product_code'       => 'Code',
                'category'           => 'Category',
                'location'           => 'Location',
                'quantity_on_hand'   => 'On Hand',
                'available_quantity' => 'Available',
                'threshold'          => 'Reorder Level',
                'cost_price'         => 'Cost per Piece',
                'stock_value'        => 'Stock Value',
            ],
            rows: $rows,
            summary: [
                'Stock lines'     => number_format(count($rows)),
                'Total units'     => number_format($totalUnits),
                'Total valuation' => number_format($totalValue, 2),
            ],
            notes: ['Live stock snapshot valued at cost per piece. Reorder level uses the location setting or the product default.'],
            columnFormats: [
                'cost_price'  => ReportDataset::FORMAT_MONEY,
                'stock_value' => ReportDataset::FORMAT_MONEY,
            ],
        );
    }
}
