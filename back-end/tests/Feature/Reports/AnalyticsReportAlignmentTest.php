<?php

namespace Tests\Feature\Reports;

use App\Reports\Definitions\InventorySummaryReport;
use App\Reports\Definitions\LocationPerformanceReport;
use App\Reports\Definitions\LowStockReport;
use App\Reports\ReportParameters;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Support\Facades\DB;

class AnalyticsReportAlignmentTest extends ReportTestCase
{
    public function test_stock_charts_records_and_reports_share_cost_thresholds_and_archived_exclusions(): void
    {
        $location = $this->makeLocation('Alignment warehouse');
        $admin = $this->makeUserAt($location, 'admin');
        $material = $this->makeInstallationMaterial(cost: 80);
        DB::table('installation_material_details')->insert([
            'product_id' => $material->product_id, 'pieces_per_package' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $material->update(['recommended_stocks' => 10]);
        $this->setStock($location->location_id, $material->product_id, 15)->update(['reorder_level' => 20]);
        $zero = $this->makeAppliance(cost: 200);
        $zero->update(['recommended_stocks' => 0]);
        $this->setStock($location->location_id, $zero->product_id, 0);
        $archived = $this->makeAppliance(cost: 10000);
        $this->setStock($location->location_id, $archived->product_id, 100);
        $archived->delete();

        $params = new ReportParameters(['location_id' => $location->location_id]);
        $analytics = app(DashboardAnalyticsService::class)->stockLevel($location->location_id, null);
        $inventory = app(InventorySummaryReport::class)->build($params, $admin);
        $low = app(LowStockReport::class)->build($params, $admin);

        $this->assertSame($inventory->rows, $analytics['stockRecords']);
        $this->assertSame(15, $analytics['statsData']['totalUnits']);
        $this->assertSame(24.0, $analytics['statsData']['totalStockValue']);
        $this->assertSame(2, $analytics['reorderCompliance']['belowReorderPoint']);
        $this->assertCount(2, $low->rows);
        $this->assertSame(20, collect($low->rows)->firstWhere('product_code', $material->product_code)['threshold']);
        $this->assertSame($analytics['statsData']['totalStockValue'], (float) array_sum(array_column($inventory->rows, 'stock_value')));
        $performance = app(DashboardAnalyticsService::class)->locationPerformance($location->location_id, null);
        $this->assertSame(24.0, $performance['branchPerformanceData'][0]['totalValue']);
        $locationReport = app(LocationPerformanceReport::class)->build($params, $admin);
        $this->assertSame(24.0, $locationReport->rows[0]['closing_valuation']);
    }

    public function test_movement_chart_and_records_share_category_and_the_entire_30_day_period(): void
    {
        $location = $this->makeLocation('Movement alignment');
        $category = DB::table('category_lookup')->insertGetId([
            'category_name' => 'Alignment-' . uniqid(), 'category_type' => 'product',
            'created_at' => now(), 'updated_at' => now(),
        ], 'category_id');
        $included = $this->makeAppliance();
        $included->update(['category_id' => $category]);
        $excluded = $this->makeAppliance();
        $this->setStock($location->location_id, $included->product_id, 20);
        $this->setStock($location->location_id, $excluded->product_id, 20);
        foreach ([29 => 7, 10 => 3, 0 => 2] as $daysAgo => $units) {
            $date = now()->subDays($daysAgo);
            $sale = DB::table('sales')->insertGetId([
                'location_id' => $location->location_id, 'invoice_number' => 'ALIGN-' . uniqid(),
                'sale_date' => $date->toDateString(), 'order_date' => $date->toDateString(),
                'created_at' => $date, 'updated_at' => $date,
            ], 'sales_id');
            foreach ([$included->product_id => $units, $excluded->product_id => 999] as $productId => $qty) {
                DB::table('sales_details')->insert([
                    'sales_id' => $sale, 'product_id' => $productId, 'quantity' => $qty,
                    'unit_price' => 1, 'subtotal' => $qty, 'created_at' => $date, 'updated_at' => $date,
                ]);
            }
        }
        $result = app(DashboardAnalyticsService::class)->movement($location->location_id, $category);
        $this->assertSame([7, 0, 3, 2], $result['weeklyMovement']);
        $this->assertCount(1, $result['movementRecords']);
        $this->assertSame(12.0, $result['movementRecords'][0]['unitsOut']);
        foreach (range(1, 4) as $week) {
            $this->assertSame($result['weeklyMovement'][$week - 1], (int) array_sum(array_column($result['movementRecords'], "week{$week}")));
        }
    }
}
