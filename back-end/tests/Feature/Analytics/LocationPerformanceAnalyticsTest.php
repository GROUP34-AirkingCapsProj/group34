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

    public function test_ordering_is_highest_efficiency_first_ties_broken_by_total_value(): void
    {
        // Both optimal (efficiency 100), but A holds more stock value than B.
        $highValueId = $this->makeLocation('High Value Optimal');
        $this->stock($highValueId, 90); // value 9000, optimal

        $lowValueId = $this->makeLocation('Low Value Optimal');
        $this->stock($lowValueId, 50); // value 5000, optimal

        $poorId = $this->makeLocation('Poor Efficiency');
        $this->stock($poorId, 5); // low stock -> efficiency 0

        $result = $this->service->locationPerformance(null);
        $names  = collect($result['branchPerformanceData'])
            ->whereIn('name', ['High Value Optimal', 'Low Value Optimal', 'Poor Efficiency'])
            ->pluck('name')
            ->values()
            ->all();

        $this->assertSame(
            ['High Value Optimal', 'Low Value Optimal', 'Poor Efficiency'],
            $names,
            'Highest efficiency first; ties on efficiency broken by higher total value.'
        );
    }

    public function test_avg_turnover_matches_consumed_over_on_hand_ratio(): void
    {
        $locationId = $this->makeLocation('Turnover Warehouse');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Turnover Product',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => 20,
            'available_quantity' => 20,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $salesId = (int) DB::table('sales')->insertGetId([
            'invoice_number' => 'TST-INV-' . uniqid(),
            'location_id'    => $locationId,
            'sale_date'      => now()->subDays(5)->toDateString(),
            'order_date'     => now()->subDays(5)->toDateString(),
            'created_at'     => now()->subDays(5),
            'updated_at'     => now()->subDays(5),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            'sales_id'   => $salesId,
            'product_id' => $productId,
            'quantity'   => 40,
            'unit_price' => 100,
            'subtotal'   => 4000,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $result = $this->service->locationPerformance(null);
        $row    = collect($result['branchPerformanceData'])->firstWhere('name', 'Turnover Warehouse');

        // 40 consumed / 20 on hand = 2x, matching the Movement tab's definition.
        $this->assertSame(2, $row['avgTurnover']);
    }

    public function test_category_filter_narrows_location_performance(): void
    {
        $locationId = $this->makeLocation('Category Warehouse');

        $catA = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'CatA-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $catB = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'CatB-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        foreach ([$catA, $catB] as $categoryId) {
            $productId = (int) DB::table('products')->insertGetId([
                'product_code'       => 'TST-' . uniqid(),
                'product_name'       => 'Category Product',
                'product_type'       => 'appliance',
                'unit_price'         => 100,
                'cost_price'         => 80,
                'recommended_stocks' => 10,
                'max_stock_level'    => 100,
                'status_id'          => 3,
                'category_id'        => $categoryId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ], 'product_id');

            DB::table('inventory')->insert([
                'location_id'        => $locationId,
                'product_id'         => $productId,
                'quantity_on_hand'   => 50,
                'available_quantity' => 50,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $resultAll = $this->service->locationPerformance(null);
        $rowAll    = collect($resultAll['branchPerformanceData'])->firstWhere('name', 'Category Warehouse');
        $this->assertSame(2, $rowAll['items']);

        $resultFiltered = $this->service->locationPerformance($catA);
        $rowFiltered    = collect($resultFiltered['branchPerformanceData'])->firstWhere('name', 'Category Warehouse');
        $this->assertSame(1, $rowFiltered['items']);
    }

    public function test_stock_value_formatted_bands(): void
    {
        $subThousandId  = $this->makeLocation('Sub Thousand Location');
        $this->stock($subThousandId, 5); // value 500

        $thousandsId = $this->makeLocation('Thousands Location');
        $this->stock($thousandsId, 50); // value 5000

        $millionsId = $this->makeLocation('Millions Location');
        $this->stock($millionsId, 20000); // value 2,000,000

        $result = $this->service->locationPerformance(null);
        $rowFor = fn (string $name) => collect($result['branchPerformanceData'])->firstWhere('name', $name);

        $this->assertSame('₱500', $rowFor('Sub Thousand Location')['stockValueFormatted']);
        $this->assertSame('₱5K', $rowFor('Thousands Location')['stockValueFormatted']);
        $this->assertSame('₱2.0M', $rowFor('Millions Location')['stockValueFormatted']);
    }
}
