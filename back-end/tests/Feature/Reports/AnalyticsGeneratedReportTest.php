<?php

namespace Tests\Feature\Reports;

use App\Reports\Definitions\AnalyticsReport;
use App\Reports\ReportCharts;
use App\Reports\ReportDataset;
use App\Reports\ReportParameters;
use App\Reports\ReportRegistry;
use App\Reports\Renderers\PdfReportRenderer;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Support\Facades\DB;

class AnalyticsGeneratedReportTest extends ReportTestCase
{
    public function test_stock_report_reuses_the_analytics_rows_and_renders_a_real_pdf_with_graphs(): void
    {
        $location = $this->makeLocation('Graph warehouse');
        $user = $this->makeUserAt($location, 'admin');
        $product = $this->makeAppliance(cost: 100);
        $product->update(['recommended_stocks' => 10]);
        $this->setStock($location->location_id, $product->product_id, 3);
        $definition = app(ReportRegistry::class)->get('analytics-stock-level');
        $this->assertTrue($definition->availableTo($user));
        $dataset = $definition->build(new ReportParameters(['location_id' => $location->location_id]), $user);
        $analytics = app(DashboardAnalyticsService::class)->stockLevel($location->location_id, null);
        $this->assertSame($analytics['stockRecords'], $dataset->rows);
        $graphs = app(ReportCharts::class)->forDataset($dataset);
        $this->assertSame(300.0, $graphs[0]['bars'][0]['value']);
        $this->assertSame(1.0, $graphs[1]['bars'][0]['value']);
        $pdf = app(PdfReportRenderer::class)->render($dataset, $definition->title(), ['Location' => $location->location_name]);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_all_five_analytics_reports_are_registered_and_preserve_location_scope(): void
    {
        $mine = $this->makeLocation('Mine');
        $theirs = $this->makeLocation('Theirs');
        // warehouse_personnel is the read-scoped role. It does not hold
        // analytics_dashboard.read — no read-scoped role does since the branch
        // manager moved into the all-location group — so availableTo() needs the
        // per-user override an admin would grant, exactly as ReportApiTest does.
        $user = $this->makeUserAt($mine, 'warehouse_personnel');
        $this->grantPermission($user, 'analytics_dashboard', 'read');
        $product = $this->makeAppliance();
        $this->setStock($mine->location_id, $product->product_id, 2);
        $this->setStock($theirs->location_id, $product->product_id, 999);
        foreach (AnalyticsReport::VIEWS as $view) {
            $this->assertTrue(app(ReportRegistry::class)->has('analytics-' . $view));
        }
        foreach (['stock-level', 'movement', 'branch-performance'] as $view) {
            $definition = app(ReportRegistry::class)->get('analytics-' . $view);
            $this->assertTrue($definition->availableTo($user));
            $own = $definition->build(new ReportParameters([]), $user);
            $this->assertCount(1, $own->rows);
            $foreign = $definition->build(new ReportParameters(['location_id' => $theirs->location_id]), $user);
            $this->assertSame([], $foreign->rows);
        }
    }

    public function test_forecast_report_includes_all_records_and_uses_their_costs_in_its_graph(): void
    {
        $location = $this->makeLocation('Forecast warehouse');
        $user = $this->makeUserAt($location, 'admin');
        for ($i = 0; $i < 13; $i++) {
            $product = $this->makeAppliance(cost: 100);
            DB::table('restock_recommendations')->insert([
                'product_id' => $product->product_id, 'location_id' => $location->location_id,
                'current_stock' => 1, 'reorder_level' => 10, 'recommended_quantity' => 5,
                'priority' => 'critical', 'avg_daily_demand' => 1, 'days_of_cover' => 1,
                'lead_time_days' => 7, 'on_order_quantity' => 0, 'run_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $dataset = app(ReportRegistry::class)->get('analytics-predictions')
            ->build(new ReportParameters(['location_id' => $location->location_id]), $user);
        $this->assertCount(13, $dataset->rows);
        $this->assertSame(13.0, $dataset->charts[0]['bars'][0]['value']);
        $this->assertSame((float) array_sum(array_column($dataset->rows, 'estCost')), $dataset->charts[1]['bars'][0]['value']);
        $this->assertNotEmpty($dataset->rows[0]['computedAt']);
    }

    public function test_chart_limits_keep_every_group_in_the_total_and_do_not_sum_percentages(): void
    {
        $bars = array_map(fn ($n) => ['label' => "Group {$n}", 'value' => $n], range(1, 15));
        $chart = ReportCharts::bar('Cost', $bars, 'PHP');
        $this->assertCount(12, $chart['bars']);
        $this->assertSame(120.0, array_sum(array_column($chart['bars'], 'value')));
        $percent = ReportCharts::bar('Compliance', $bars, '%');
        $this->assertCount(15, $percent['bars']);
    }

    public function test_confirmed_loss_report_graphs_use_the_same_loss_records(): void
    {
        $location = $this->makeLocation('Loss warehouse');
        $user = $this->makeUserAt($location, 'admin');
        $rows = [['date' => '2026-10-09', 'itemName' => 'Aircon', 'branchName' => 'Loss warehouse',
            'itemCategory' => 'Appliances', 'lossType' => 'damage', 'qty' => 2, 'lossValue' => 400.5, 'reason' => 'Confirmed damage']];
        $service = $this->mock(DashboardAnalyticsService::class, function ($mock) use ($location, $rows) {
            $mock->shouldReceive('profitLoss')->once()->with($location->location_id, null)->andReturn([
                'lossRecords' => $rows, 'profitLossStats' => ['totalProfitLoss' => 400.5, 'pendingReports' => 3],
            ]);
        });
        $dataset = (new AnalyticsReport('profit-loss', $service))
            ->build(new ReportParameters(['location_id' => $location->location_id]), $user);
        $this->assertSame($rows, $dataset->rows);
        $this->assertSame(400.5, $dataset->charts[0]['bars'][0]['value']);
        $this->assertSame(400.5, $dataset->charts[1]['bars'][0]['value']);
        $this->assertSame('3', $dataset->summary['Pending incident reports (excluded)']);
    }

    public function test_analytics_definitions_stay_hidden_without_analytics_permission(): void
    {
        $user = $this->makeUserAt($this->makeLocation(), 'warehouse_personnel');
        $keys = array_map(fn ($definition) => $definition->key(), app(ReportRegistry::class)->availableTo($user));
        $this->assertContains('inventory-summary', $keys);
        foreach (AnalyticsReport::VIEWS as $view) {
            $this->assertNotContains('analytics-' . $view, $keys);
        }
    }

    public function test_rendered_graph_labels_are_escaped_and_values_match_raw_records(): void
    {
        $dataset = new ReportDataset(columns: ['stock_value' => 'Cost'], rows: [
            ['location' => '<script>unsafe</script>', 'stock_value' => 150.25],
            ['location' => '<script>unsafe</script>', 'stock_value' => 10.25],
        ]);
        $graphs = app(ReportCharts::class)->forDataset($dataset);
        $this->assertSame(160.5, $graphs[0]['bars'][0]['value']);
        $html = view('reports.document', [
            'title' => 'Graph report', 'meta' => [], 'columns' => $dataset->columns, 'rows' => $dataset->rows,
            'sections' => [], 'summary' => [], 'notes' => [], 'charts' => $graphs, 'generated_at' => '2026-10-09',
            'company' => ['name' => 'Airking', 'address' => '', 'email' => '', 'phone' => ''],
        ])->render();
        $this->assertStringContainsString('Stock value at cost by location', $html);
        $this->assertStringContainsString('160.50', $html);
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>unsafe</script>', $html);
    }

    public function test_the_analytics_reports_span_every_location_for_a_branch_manager(): void
    {
        $mine = $this->makeLocation('BM Mine');
        $theirs = $this->makeLocation('BM Theirs');
        $user = $this->makeUserAt($mine, 'branch_manager');
        $product = $this->makeAppliance();
        $this->setStock($mine->location_id, $product->product_id, 2);
        $this->setStock($theirs->location_id, $product->product_id, 999);

        $definition = app(ReportRegistry::class)->get('analytics-stock-level');
        $this->assertTrue($definition->availableTo($user));

        // Unfiltered: both locations' rows. Filtered to the other location: its
        // rows, not an empty set as a read-scoped user would get.
        $this->assertCount(2, $definition->build(new ReportParameters([]), $user)->rows);
        $this->assertCount(1, $definition->build(new ReportParameters(['location_id' => $theirs->location_id]), $user)->rows);
    }
}
