<?php

namespace App\Reports\Definitions;

use App\Models\Inventory;
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
    private const SOURCE_LOCATION = 'Location reorder level';
    private const SOURCE_PRODUCT  = 'Product default';

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

        foreach ($query->get() as $inventory) {
            $product = $inventory->product;
            if (! $product) {
                continue;
            }

            $locationLevel = (int) $inventory->reorder_level;
            $productLevel  = (int) ($product->recommended_stocks ?? 0);

            // A location level of 0 means "not set here", not "never reorder".
            $threshold = $locationLevel > 0 ? $locationLevel : $productLevel;
            $source    = $locationLevel > 0 ? self::SOURCE_LOCATION : self::SOURCE_PRODUCT;

            if ($threshold <= 0) {
                continue;
            }

            $onHand = (int) $inventory->quantity_on_hand;
            if ($onHand > $threshold) {
                continue;
            }

            $rows[] = [
                'product'          => $product->product_name,
                'product_code'     => $product->product_code,
                'category'         => $product->category?->category_name ?? '—',
                'location'         => $inventory->location?->location_name ?? '—',
                'quantity_on_hand' => $onHand,
                'threshold'        => $threshold,
                'threshold_source' => $source,
                'shortfall'        => max(0, $threshold - $onHand),
            ];
        }

        usort($rows, fn ($a, $b) => $b['shortfall'] <=> $a['shortfall']);

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
            notes: ['Threshold is the location reorder level where set, otherwise the product default.'],
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
