<?php

namespace App\Reports\Definitions;

use App\Models\Inventory;
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
        $query = Inventory::query()
            ->forUser($user)
            ->with(['location', 'product.category']);

        if ($params->has('location_id')) {
            $query->where('location_id', $params->int('location_id'));
        }

        if ($params->has('category_id')) {
            $categoryId = $params->int('category_id');
            $query->whereHas('product', fn ($q) => $q->where('category_id', $categoryId));
        }

        $rows = [];
        $totalUnits = 0;
        $totalValue = 0.0;

        foreach ($query->get() as $inventory) {
            $product = $inventory->product;

            // Product uses SoftDeletes, so an archived product leaves its
            // inventory rows behind with a null relation. Emitting one gives a
            // row of '—' worth 0 whose quantity still lands in "Total units",
            // so the valuation under-reports against its own unit count and
            // against LocationPerformanceReport's closing value, which
            // excludes deleted products. Skipped, as LowStockReport does.
            if (! $product) {
                continue;
            }

            $cost    = (float) $product->cost_price;
            $qty     = (int) $inventory->quantity_on_hand;
            $value   = $cost * $qty;

            $totalUnits += $qty;
            $totalValue += $value;

            $rows[] = [
                'product'            => $product->product_name,
                'product_code'       => $product->product_code,
                'category'           => $product->category?->category_name ?? '—',
                'location'           => $inventory->location?->location_name ?? '—',
                'quantity_on_hand'   => $qty,
                'available_quantity' => (int) $inventory->available_quantity,
                'reorder_level'      => (int) $inventory->reorder_level,
                // Raw numbers, not formatted strings — CSV writes these as-is
                // and must stay summable/pivotable; the PDF's display format
                // (thousands separators, 2 decimals) is declared below via
                // columnFormats instead of baked in here.
                'cost_price'         => round($cost, 2),
                'stock_value'        => round($value, 2),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['location'], $a['product']] <=> [$b['location'], $b['product']]);

        return new ReportDataset(
            columns: [
                'product'            => 'Product',
                'product_code'       => 'Code',
                'category'           => 'Category',
                'location'           => 'Location',
                'quantity_on_hand'   => 'On Hand',
                'available_quantity' => 'Available',
                'reorder_level'      => 'Reorder Level',
                'cost_price'         => 'Unit Cost',
                'stock_value'        => 'Stock Value',
            ],
            rows: $rows,
            summary: [
                'Stock lines'     => number_format(count($rows)),
                'Total units'     => number_format($totalUnits),
                'Total valuation' => number_format($totalValue, 2),
            ],
            notes: ['Stock levels are current as of the generation time; this report has no historical range.'],
            columnFormats: [
                'cost_price'  => ReportDataset::FORMAT_MONEY,
                'stock_value' => ReportDataset::FORMAT_MONEY,
            ],
        );
    }
}
