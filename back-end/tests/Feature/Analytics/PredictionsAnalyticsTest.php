<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Analytics\RestockAnalyticsService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PredictionsAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardAnalyticsService $service;
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);

        $this->warehouseId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Prediction Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function recommendation(array $overrides = []): int
    {
        $productId = (int) DB::table('products')->insertGetId([
            'product_code'    => 'TST-' . uniqid(),
            'product_name'    => 'Predicted Product',
            'product_type'    => 'appliance',
            'unit_price'      => 100,
            'cost_price'      => 80,
            'safety_stock'    => 5,
            'max_stock_level' => 100,
            'status_id'       => 3,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'product_id');

        DB::table('restock_recommendations')->insert(array_merge([
            'product_id'           => $productId,
            'location_id'          => $this->warehouseId,
            'current_stock'        => 4,
            'reorder_level'        => 20,
            'recommended_quantity' => 50,
            'priority'             => 'critical',
            'avg_daily_demand'     => 2.0,
            'days_of_cover'        => 2.0,
            'lead_time_days'       => 7.0,
            'on_order_quantity'    => 0,
            'run_at'               => now(),
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $overrides));

        return $productId;
    }

    public function test_reads_persisted_recommendations(): void
    {
        $this->recommendation();

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(1, $result['predictionsStats']['restockNeeded']);
        $this->assertSame(1, $result['predictionsStats']['criticalPriority']);
        $this->assertSame(1, $result['predictionsStats']['predictedStockouts']);
        $this->assertEqualsWithDelta(4000.0, $result['predictionsStats']['estRestockCost'], 0.01);
    }

    public function test_priority_summary_groups_units_and_cost(): void
    {
        $this->recommendation(['priority' => 'critical', 'recommended_quantity' => 10]);
        $this->recommendation(['priority' => 'high',     'recommended_quantity' => 20]);
        $this->recommendation(['priority' => 'high',     'recommended_quantity' => 5]);

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(1,  $result['prioritySummary']['critical']['count']);
        $this->assertSame(10, $result['prioritySummary']['critical']['units']);
        $this->assertSame(2,  $result['prioritySummary']['high']['count']);
        $this->assertSame(25, $result['prioritySummary']['high']['units']);
        $this->assertEqualsWithDelta(2000.0, $result['prioritySummary']['high']['cost'], 0.01);
    }

    public function test_timeline_only_includes_items_running_out_within_thirty_days(): void
    {
        $this->recommendation(['days_of_cover' => 3,   'priority' => 'critical']);
        $this->recommendation(['days_of_cover' => 400, 'priority' => 'medium']);

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertCount(1, $result['stockoutTimeline']);
        $this->assertSame(3, $result['stockoutTimeline'][0]['daysLeft']);
        $this->assertSame(1, $result['predictionsStats']['predictedStockouts']);
    }

    public function test_safety_stock_comes_from_the_product(): void
    {
        $this->recommendation();

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(5, $result['restockRecommendations'][0]['safetyStock']);
    }

    public function test_empty_table_triggers_a_recompute(): void
    {
        $this->mock(RestockAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('run')->once();
        });

        $service = app(DashboardAnalyticsService::class);

        $result = $service->predictions(null, null);

        $this->assertArrayHasKey('predictionsStats', $result);
    }

    public function test_fresh_table_does_not_trigger_a_recompute(): void
    {
        $this->recommendation(['created_at' => now()]);

        $this->mock(RestockAnalyticsService::class, function ($mock) {
            $mock->shouldNotReceive('run');
        });

        $service = app(DashboardAnalyticsService::class);

        $service->predictions($this->warehouseId, null);
    }

    public function test_stale_table_triggers_a_recompute(): void
    {
        $this->recommendation(['created_at' => now()->subDays(3)]);

        $this->mock(RestockAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('run')->once();
        });

        $service = app(DashboardAnalyticsService::class);

        $service->predictions($this->warehouseId, null);
    }

    public function test_lock_timeout_during_recompute_does_not_fail_the_request(): void
    {
        $this->mock(RestockAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andThrow(new LockTimeoutException());
        });

        $service = app(DashboardAnalyticsService::class);

        $result = $service->predictions(null, null);

        $this->assertArrayHasKey('predictionsStats', $result);
        $this->assertArrayHasKey('stockoutTimeline', $result);
        $this->assertArrayHasKey('restockRecommendations', $result);
        $this->assertArrayHasKey('prioritySummary', $result);
    }

    public function test_uppercase_priority_normalizes_to_lowercase(): void
    {
        $this->recommendation(['priority' => 'CRITICAL']);

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertSame(1, $result['prioritySummary']['critical']['count']);
        $this->assertSame('critical', $result['restockRecommendations'][0]['priority']);
    }

    public function test_null_days_of_cover_is_excluded_from_stockout_predictions(): void
    {
        $this->recommendation(['days_of_cover' => null]);

        $result = $this->service->predictions($this->warehouseId, null);

        $this->assertCount(0, $result['stockoutTimeline']);
        $this->assertSame(0, $result['predictionsStats']['predictedStockouts']);
    }

    public function test_category_filter_narrows_results(): void
    {
        $categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'Filtered Category ' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $matchingProductId = $this->recommendation();
        DB::table('products')->where('product_id', $matchingProductId)->update(['category_id' => $categoryId]);

        $this->recommendation(); // different product, no category assigned

        $result = $this->service->predictions($this->warehouseId, $categoryId);

        $this->assertSame(1, $result['predictionsStats']['restockNeeded']);
    }
}
