<?php

namespace Tests\Feature\Reports;

use App\Reports\Definitions\InventorySummaryReport;
use App\Reports\ReportDataset;
use App\Reports\ReportParameters;

class InventorySummaryReportTest extends ReportTestCase
{
    public function test_it_values_stock_at_cost_price(): void
    {
        $location = $this->makeLocation('CDO Warehouse A');
        $product  = $this->makeAppliance(cost: 24000);
        $this->setStock($location->location_id, $product->product_id, 3);

        $admin = $this->makeUserAt($location, 'admin');

        $dataset = app(InventorySummaryReport::class)
            ->build(new ReportParameters(['location_id' => $location->location_id]), $admin);

        $row = collect($dataset->rows)->firstWhere('product', $product->product_name);

        $this->assertNotNull($row, 'The stocked product must appear.');
        $this->assertSame(3, $row['quantity_on_hand']);

        // The dataset carries the raw number, not a display string — CSV
        // writes exactly this value, unquoted, so a spreadsheet can sum it.
        $this->assertSame(72000.0, $row['stock_value']);
        $this->assertSame(24000.0, $row['cost_price']);

        // Thousands separators and decimals are a PDF display concern, so
        // the definition declares the format rather than baking it in.
        $this->assertSame(ReportDataset::FORMAT_MONEY, $dataset->columnFormats['stock_value']);
        $this->assertSame(ReportDataset::FORMAT_MONEY, $dataset->columnFormats['cost_price']);
    }

    /**
     * Product uses SoftDeletes, so an archived product leaves its inventory
     * rows behind with a null relation. Emitting one produces a row of '—'
     * with a zero value that still adds its quantity to "Total units", so the
     * valuation silently under-reports against the unit count — and against
     * LocationPerformanceReport, which excludes deleted products from its own
     * closing valuation. LowStockReport already skips these.
     */
    public function test_stock_of_an_archived_product_is_left_out_of_the_rows_and_the_totals(): void
    {
        $location = $this->makeLocation('ArchivedProdLoc');
        $live     = $this->makeAppliance(cost: 1000);
        $archived = $this->makeAppliance(cost: 500);

        $this->setStock($location->location_id, $live->product_id, 2);
        $this->setStock($location->location_id, $archived->product_id, 7);

        $admin = $this->makeUserAt($location, 'admin');

        $archived->delete();

        $dataset = app(InventorySummaryReport::class)
            ->build(new ReportParameters(['location_id' => $location->location_id]), $admin);

        $this->assertCount(1, $dataset->rows, 'Only the live product may produce a row.');
        $this->assertSame($live->product_name, $dataset->rows[0]['product']);

        $this->assertSame(number_format(2), $dataset->summary['Total units'],
            'Units of an archived product must not be counted while its value is not.');
        $this->assertSame(number_format(2000.0, 2), $dataset->summary['Total valuation']);
        $this->assertSame(number_format(1), $dataset->summary['Stock lines']);
    }

    public function test_it_declares_no_date_range(): void
    {
        $types = collect(app(InventorySummaryReport::class)->parameters())->pluck('type')->all();

        $this->assertNotContains('date_range', $types,
            'Stock is a live snapshot — there is no history table to reconstruct an as-of date from.');
    }

    public function test_a_scoped_user_sees_only_their_own_location(): void
    {
        $mine   = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        $product = $this->makeAppliance();

        $this->setStock($mine->location_id, $product->product_id, 5);
        $this->setStock($theirs->location_id, $product->product_id, 9);

        $scoped = $this->makeUserAt($mine, 'branch_manager');

        $dataset = app(InventorySummaryReport::class)->build(new ReportParameters([]), $scoped);

        $this->assertSame(['Mine'], collect($dataset->rows)->pluck('location')->unique()->values()->all());
    }
}
