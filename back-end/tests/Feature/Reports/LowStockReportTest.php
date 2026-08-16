<?php

namespace Tests\Feature\Reports;

use App\Reports\Definitions\LowStockReport;
use App\Reports\ReportParameters;

class LowStockReportTest extends ReportTestCase
{
    public function test_it_uses_the_per_location_reorder_level(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 2);
        $inventory->update(['reorder_level' => 5]);

        $dataset = app(LowStockReport::class)->build(new ReportParameters([]), $admin);
        $row = collect($dataset->rows)->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row, 'Stock of 2 against a reorder level of 5 is low.');
        $this->assertSame(5, $row['threshold']);
        $this->assertSame(3, $row['shortfall']);
        $this->assertSame('Location reorder level', $row['threshold_source']);
    }

    /**
     * The bug in the closure this replaces: it compared against the global
     * products.recommended_stocks and ignored inventory.reorder_level entirely,
     * so per-location shortfalls were invisible.
     */
    public function test_it_falls_back_to_the_product_default_when_no_location_level_is_set(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $product->update(['recommended_stocks' => 8]);
        $admin = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 1);
        $inventory->update(['reorder_level' => 0]);

        $dataset = app(LowStockReport::class)->build(new ReportParameters([]), $admin);
        $row = collect($dataset->rows)->firstWhere('product_code', $product->product_code);

        $this->assertNotNull($row);
        $this->assertSame(8, $row['threshold']);
        $this->assertSame('Product default', $row['threshold_source']);
    }

    public function test_well_stocked_items_are_absent(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 50);
        $inventory->update(['reorder_level' => 5]);

        $dataset = app(LowStockReport::class)->build(new ReportParameters([]), $admin);

        $this->assertNull(collect($dataset->rows)->firstWhere('product_code', $product->product_code));
    }
}
