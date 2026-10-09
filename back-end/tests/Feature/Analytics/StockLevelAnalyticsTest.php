<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockLevelAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardAnalyticsService $service;
    private int $locationId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);

        $this->locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Analytics Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $this->categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'AnalyticsCat-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');
    }

    private function stock(array $product, array $inventory): int
    {
        $productId = (int) DB::table('products')->insertGetId(array_merge([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Analytics Product',
            'product_type'       => 'appliance',
            'category_id'        => $this->categoryId,
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'safety_stock'       => 5,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $product), 'product_id');

        DB::table('inventory')->insert(array_merge([
            'location_id'        => $this->locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => 50,
            'available_quantity' => 50,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $inventory));

        return $productId;
    }

    private function makeLocation(string $label): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => $label,
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    public function test_classifies_each_stock_bucket(): void
    {
        $this->stock([], ['quantity_on_hand' => 0,   'available_quantity' => 0]);
        $this->stock([], ['quantity_on_hand' => 8,   'available_quantity' => 8]);
        $this->stock([], ['quantity_on_hand' => 50,  'available_quantity' => 50]);
        $this->stock([], ['quantity_on_hand' => 150, 'available_quantity' => 150]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertSame(1, $result['stockDistribution']['critical']['count']);
        $this->assertSame(1, $result['stockDistribution']['lowStock']['count']);
        $this->assertSame(1, $result['stockDistribution']['optimal']['count']);
        $this->assertSame(1, $result['stockDistribution']['overstock']['count']);
        $this->assertSame(25, $result['stockDistribution']['critical']['percentage']);
    }

    public function test_location_reorder_level_overrides_product_recommended_stocks(): void
    {
        $this->stock(['recommended_stocks' => 10], [
            'quantity_on_hand'   => 30,
            'available_quantity' => 30,
            'reorder_level'      => 40,
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertSame(1, $result['stockDistribution']['lowStock']['count']);
        $this->assertSame(0, $result['stockDistribution']['optimal']['count']);
    }

    public function test_null_max_stock_level_is_never_overstock(): void
    {
        $this->stock(['max_stock_level' => null], [
            'quantity_on_hand'   => 99999,
            'available_quantity' => 99999,
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertSame(0, $result['stockDistribution']['overstock']['count']);
        $this->assertSame(1, $result['stockDistribution']['optimal']['count']);
    }

    public function test_installation_material_is_valued_per_piece(): void
    {
        $productId = $this->stock(
            ['product_type' => 'installation_material', 'unit_price' => 100],
            ['quantity_on_hand' => 10, 'available_quantity' => 10]
        );

        DB::table('installation_material_details')->insert([
            'product_id'         => $productId,
            'pieces_per_package' => 50,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertEqualsWithDelta(16.0, $result['statsData']['totalStockValue'], 0.01);
    }

    public function test_category_filter_narrows_results(): void
    {
        $otherCategoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'OtherCat-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $this->stock([], ['quantity_on_hand' => 10, 'available_quantity' => 10]);
        $this->stock(['category_id' => $otherCategoryId], ['quantity_on_hand' => 10, 'available_quantity' => 10]);

        $all      = $this->service->stockLevel($this->locationId, null);
        $filtered = $this->service->stockLevel($this->locationId, $this->categoryId);

        $this->assertSame(20, $all['statsData']['totalUnits']);
        $this->assertSame(10, $filtered['statsData']['totalUnits']);
    }

    public function test_org_wide_call_aggregates_across_all_locations(): void
    {
        $otherLocationId = $this->makeLocation('Analytics Warehouse 2');

        $this->stock([], ['quantity_on_hand' => 10, 'available_quantity' => 10]);

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Analytics Product 2',
            'product_type'       => 'appliance',
            'category_id'        => $this->categoryId,
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'safety_stock'       => 5,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $otherLocationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => 15,
            'available_quantity' => 15,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $scoped  = $this->service->stockLevel($this->locationId, null);
        $orgWide = $this->service->stockLevel(null, null);

        $this->assertSame(10, $scoped['statsData']['totalUnits']);
        $this->assertSame(25, $orgWide['statsData']['totalUnits']);
    }

    public function test_attention_and_overstock_items_have_expected_shape_and_ordering(): void
    {
        $this->stock(['product_name' => 'Critical Low'], [
            'quantity_on_hand'   => 2,
            'available_quantity' => 2,
        ]);
        $this->stock(['product_name' => 'Critical Zero'], [
            'quantity_on_hand'   => 0,
            'available_quantity' => 0,
        ]);
        $this->stock(['product_name' => 'Big Overstock'], [
            'quantity_on_hand'   => 500,
            'available_quantity' => 500,
        ]);

        $result = $this->service->stockLevel($this->locationId, null);

        $critical = $result['criticalItems'];
        $this->assertCount(2, $critical);
        $this->assertSame(['name', 'location', 'current', 'max'], array_keys($critical[0]));
        // Worst (lowest quantity) first.
        $this->assertSame('Critical Zero', $critical[0]['name']);
        $this->assertSame(0, $critical[0]['current']);
        $this->assertSame('Critical Low', $critical[1]['name']);
        $this->assertSame(2, $critical[1]['current']);
        $this->assertSame('Analytics Warehouse', $critical[0]['location']);

        $overstock = $result['overstockItems'];
        $this->assertCount(1, $overstock);
        $this->assertSame(['name', 'location', 'current', 'optimal'], array_keys($overstock[0]));
        $this->assertSame('Big Overstock', $overstock[0]['name']);
        $this->assertSame(500, $overstock[0]['current']);
        $this->assertSame(100, $overstock[0]['optimal']);
    }

    public function test_attention_items_are_complete_for_pagination(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->stock(['product_name' => "Critical {$i}"], [
                'quantity_on_hand'   => 0,
                'available_quantity' => 0,
            ]);
        }

        $result = $this->service->stockLevel($this->locationId, null);

        $this->assertCount(9, $result['criticalItems']);
    }

    public function test_location_with_no_inventory_returns_all_zero_output(): void
    {
        $emptyLocationId = $this->makeLocation('Empty Analytics Warehouse');

        $result = $this->service->stockLevel($emptyLocationId, null);

        $this->assertSame(0.0, $result['statsData']['totalStockValue']);
        $this->assertSame(0, $result['statsData']['totalUnits']);
        $this->assertSame(0, $result['statsData']['lowStockItems']);
        $this->assertSame(0, $result['statsData']['criticalItems']);

        foreach (['optimal', 'lowStock', 'critical', 'overstock'] as $bucket) {
            $this->assertSame(0, $result['stockDistribution'][$bucket]['count']);
            $this->assertSame(0, $result['stockDistribution'][$bucket]['percentage']);
        }

        $this->assertSame(0, $result['reorderCompliance']['rate']);
        $this->assertSame(0, $result['reorderCompliance']['belowReorderPoint']);
        $this->assertSame(0, $result['reorderCompliance']['aboveSafetyStock']);

        $this->assertSame([], $result['criticalItems']);
        $this->assertSame([], $result['overstockItems']);
    }
}
