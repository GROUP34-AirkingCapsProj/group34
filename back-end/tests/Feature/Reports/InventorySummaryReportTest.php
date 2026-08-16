<?php

namespace Tests\Feature\Reports;

use App\Reports\Definitions\InventorySummaryReport;
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
        $this->assertSame('72,000.00', $row['stock_value']);
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
