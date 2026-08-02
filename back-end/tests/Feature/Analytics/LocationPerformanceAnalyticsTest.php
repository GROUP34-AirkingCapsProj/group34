<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationPerformanceAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);
    }

    private function makeLocation(string $name, string $type = 'warehouse'): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'T' . substr(uniqid(), -8),
            'location_name' => $name,
            'location_type' => $type,
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function stock(int $locationId, int $qty, int $recommended = 10, ?int $max = 100): void
    {
        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Perf Product',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => $recommended,
            'max_stock_level'    => $max,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_groups_by_real_location_name_not_unknown(): void
    {
        $locationId = $this->makeLocation('Named Warehouse');
        $this->stock($locationId, 50);

        $result = $this->service->locationPerformance(null);

        $row = collect($result['branchPerformanceData'])->firstWhere('name', 'Named Warehouse');
        $this->assertNotNull($row, 'Location must be grouped by its real name.');
        $this->assertSame(1, $row['items']);
    }

    public function test_efficiency_score_reflects_healthy_stock_share(): void
    {
        $locationId = $this->makeLocation('Half Healthy');
        $this->stock($locationId, 50);  // optimal
        $this->stock($locationId, 50);  // optimal
        $this->stock($locationId, 5);   // low
        $this->stock($locationId, 0);   // critical

        $result = $this->service->locationPerformance(null);
        $row    = collect($result['branchPerformanceData'])->firstWhere('name', 'Half Healthy');

        $this->assertSame(50, $row['efficiencyScore']);
        $this->assertSame('Needs Improvement', $row['performance']);
    }

    public function test_restock_cost_falls_back_when_max_stock_level_is_null(): void
    {
        $locationId = $this->makeLocation('No Ceiling');
        $this->stock($locationId, 2, 10, null);

        $result = $this->service->locationPerformance(null);
        $row    = collect($result['branchRestockingNeeds'])->firstWhere('name', 'No Ceiling');

        $this->assertSame(1, $row['restockNeeded']);
        // Target falls back to the reorder point (10): (10-2) x 80 = 640.
        $this->assertEqualsWithDelta(640.0, $row['restockCost'], 0.01);
    }

    public function test_filters_list_all_location_types_and_categories(): void
    {
        $this->makeLocation('A Warehouse', 'warehouse');
        $this->makeLocation('A Showroom', 'showroom');

        DB::table('category_lookup')->insert([
            'category_name' => 'FilterCat-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $filters = $this->service->filters();

        $names = collect($filters['locations'])->pluck('name');
        $this->assertContains('A Warehouse', $names);
        $this->assertContains('A Showroom', $names, 'Warehouses AND showrooms must be selectable.');
        $this->assertNotEmpty($filters['categories']);
    }
}
