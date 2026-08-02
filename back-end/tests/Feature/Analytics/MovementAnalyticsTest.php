<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\RestockAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovementAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_daily_demand_by_location_is_publicly_callable(): void
    {
        $method = new \ReflectionMethod(RestockAnalyticsService::class, 'dailyDemandByLocation');

        $this->assertTrue(
            $method->isPublic(),
            'dailyDemandByLocation must be public so DashboardAnalyticsService can reuse one demand definition.'
        );
    }

    public function test_daily_demand_includes_sales_quantities(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Demand Probe',
            'product_type' => 'appliance',
            'unit_price'   => 100,
            'cost_price'   => 80,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');

        $salesId = (int) DB::table('sales')->insertGetId([
            'invoice_number' => 'TST-INV-' . uniqid(),
            'location_id'    => $locationId,
            'sale_date'      => '2026-07-10',
            'order_date'     => '2026-07-10',
            'created_at'     => now(),
            'updated_at'     => now(),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            'sales_id'   => $salesId,
            'product_id' => $productId,
            'quantity'   => 7,
            'unit_price' => 100,
            'subtotal'   => 700,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $series = app(RestockAnalyticsService::class)
            ->dailyDemandByLocation('2026-07-01', '2026-07-31');

        $this->assertSame(7.0, $series[$locationId][$productId]['2026-07-10']);
    }

    public function test_consumption_comes_from_real_sales_not_bin_emptiness(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Movement Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Mover',
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
            'location_id'    => $locationId,
            'invoice_number' => 'TST-INV-' . uniqid(),
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

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $row = collect($result['consumptionData'])->firstWhere('name', 'Mover');

        $this->assertNotNull($row, 'The moved product must appear in consumption data.');
        // 40 consumed / 20 on hand = 2x turnover. The OLD code would have said
        // (100-20)/100 = 80% purely from bin emptiness, with no sales involved.
        $this->assertSame('2x/mo', $row['turnover']);
        $this->assertSame(20, $row['currentStock']);
    }

    public function test_product_with_no_movement_reports_zero_turnover(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Idle Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Dead Stock',
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
            'quantity_on_hand'   => 5,
            'available_quantity' => 5,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $this->assertSame(0, $result['movementStats']['avgTurnoverRate']);
        $this->assertSame([0, 0, 0, 0], $result['weeklyMovement']);
    }

    public function test_status_reflects_reorder_point_not_turnover(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Status Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $zeroQtyId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Zero Qty',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        $lowStockId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'At Reorder Point',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 100,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        // Well stocked AND fast moving: the regression this test locks down.
        // Old logic keyed "Low Stock" off turnover >= 6, so this would have
        // been mislabeled despite having 50x its reorder point on hand.
        $healthyFastId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Healthy Fast Mover',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 5000,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            [
                'location_id'        => $locationId,
                'product_id'         => $zeroQtyId,
                'quantity_on_hand'   => 0,
                'available_quantity' => 0,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'location_id'        => $locationId,
                'product_id'         => $lowStockId,
                'quantity_on_hand'   => 5,
                'available_quantity' => 5,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'location_id'        => $locationId,
                'product_id'         => $healthyFastId,
                'quantity_on_hand'   => 500,
                'available_quantity' => 500,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        $salesId = (int) DB::table('sales')->insertGetId([
            'location_id'    => $locationId,
            'invoice_number' => 'TST-INV-' . uniqid(),
            'sale_date'      => now()->subDays(5)->toDateString(),
            'order_date'     => now()->subDays(5)->toDateString(),
            'created_at'     => now()->subDays(5),
            'updated_at'     => now()->subDays(5),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            'sales_id'   => $salesId,
            'product_id' => $healthyFastId,
            'quantity'   => 3000,
            'unit_price' => 100,
            'subtotal'   => 300000,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $statusOf = fn (string $name) => collect($result['consumptionData'])->firstWhere('name', $name)['status'];

        $this->assertSame('Out of Stock', $statusOf('Zero Qty'));
        $this->assertSame('Low Stock', $statusOf('At Reorder Point'));
        $this->assertSame('In Stock', $statusOf('Healthy Fast Mover'));
    }

    public function test_fast_and_slow_movers_are_classified_by_turnover(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Classification Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $fastId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Fast Mover',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 500,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        $slowId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Slow Mover',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 500,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            [
                'location_id'        => $locationId,
                'product_id'         => $fastId,
                'quantity_on_hand'   => 50,
                'available_quantity' => 50,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'location_id'        => $locationId,
                'product_id'         => $slowId,
                'quantity_on_hand'   => 100,
                'available_quantity' => 100,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        $salesId = (int) DB::table('sales')->insertGetId([
            'location_id'    => $locationId,
            'invoice_number' => 'TST-INV-' . uniqid(),
            'sale_date'      => now()->subDays(5)->toDateString(),
            'order_date'     => now()->subDays(5)->toDateString(),
            'created_at'     => now()->subDays(5),
            'updated_at'     => now()->subDays(5),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            [
                'sales_id'   => $salesId,
                'product_id' => $fastId,
                'quantity'   => 300,
                'unit_price' => 100,
                'subtotal'   => 30000,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
            [
                'sales_id'   => $salesId,
                'product_id' => $slowId,
                'quantity'   => 200,
                'unit_price' => 100,
                'subtotal'   => 20000,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $fast = collect($result['fastMovingItems'])->firstWhere('name', 'Fast Mover');
        $slow = collect($result['slowMovingItems'])->firstWhere('name', 'Slow Mover');

        $this->assertSame(['name' => 'Fast Mover', 'category' => 'Uncategorized', 'rate' => 6], $fast);
        $this->assertSame(['name' => 'Slow Mover', 'category' => 'Uncategorized', 'rate' => 2], $slow);
    }

    public function test_weekly_bucket_places_sale_in_correct_week_index(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Bucket Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Bucket Probe',
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

        // ~10 days ago: intdiv(10, 7) = 1, so index = 3 - 1 = 2 (not 1 or 3).
        $salesId = (int) DB::table('sales')->insertGetId([
            'location_id'    => $locationId,
            'invoice_number' => 'TST-INV-' . uniqid(),
            'sale_date'      => now()->subDays(10)->toDateString(),
            'order_date'     => now()->subDays(10)->toDateString(),
            'created_at'     => now()->subDays(10),
            'updated_at'     => now()->subDays(10),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            'sales_id'   => $salesId,
            'product_id' => $productId,
            'quantity'   => 5,
            'unit_price' => 100,
            'subtotal'   => 500,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $this->assertSame([0, 0, 5, 0], $result['weeklyMovement']);
    }

    public function test_consumption_rate_scales_against_the_busiest_product(): void
    {
        $locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Peak Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $busyId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Busy Product',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 500,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        $quietId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Quiet Product',
            'product_type'       => 'appliance',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'max_stock_level'    => 500,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            [
                'location_id'        => $locationId,
                'product_id'         => $busyId,
                'quantity_on_hand'   => 200,
                'available_quantity' => 200,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
            [
                'location_id'        => $locationId,
                'product_id'         => $quietId,
                'quantity_on_hand'   => 200,
                'available_quantity' => 200,
                'reorder_level'      => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ],
        ]);

        $salesId = (int) DB::table('sales')->insertGetId([
            'location_id'    => $locationId,
            'invoice_number' => 'TST-INV-' . uniqid(),
            'sale_date'      => now()->subDays(5)->toDateString(),
            'order_date'     => now()->subDays(5)->toDateString(),
            'created_at'     => now()->subDays(5),
            'updated_at'     => now()->subDays(5),
        ], 'sales_id');

        DB::table('sales_details')->insert([
            [
                'sales_id'   => $salesId,
                'product_id' => $busyId,
                'quantity'   => 100,
                'unit_price' => 100,
                'subtotal'   => 10000,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
            [
                'sales_id'   => $salesId,
                'product_id' => $quietId,
                'quantity'   => 50,
                'unit_price' => 100,
                'subtotal'   => 5000,
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
        ]);

        $result = app(\App\Services\Analytics\DashboardAnalyticsService::class)
            ->movement($locationId, null);

        $busy  = collect($result['consumptionData'])->firstWhere('name', 'Busy Product');
        $quiet = collect($result['consumptionData'])->firstWhere('name', 'Quiet Product');

        $this->assertSame(100, $busy['consumptionRate']);
        $this->assertSame(50, $quiet['consumptionRate']);
    }
}
