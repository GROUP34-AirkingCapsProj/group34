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
}
