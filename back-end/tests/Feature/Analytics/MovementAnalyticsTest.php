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
}
