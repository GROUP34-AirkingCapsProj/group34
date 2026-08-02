<?php

namespace Tests\Feature\Analytics;

use App\Models\IncidentReport;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfitLossAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private int $statusOpen;
    private int $statusResolved;
    private int $statusDismissed;

    private DashboardAnalyticsService $service;
    private int $locationId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardAnalyticsService::class);

        // Hardcoded status IDs vary between dev/test DBs; resolve by name
        // through the model to stay correct regardless of seed order.
        $this->statusOpen      = IncidentReport::statusId('Open');
        $this->statusResolved  = IncidentReport::statusId('Resolved');
        $this->statusDismissed = IncidentReport::statusId('Dismissed');

        $this->locationId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Loss Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $this->userId = (int) DB::table('users')->value('user_id');
    }

    private function makeProduct(float $costPrice = 80): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Lossy Product',
            'product_type' => 'appliance',
            'unit_price'   => 100,
            'cost_price'   => $costPrice,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');
    }

    /** Mirrors what IncidentService::postDeduction() writes on confirm. */
    private function makeAdjustment(int $productId, int $deducted, string $type): int
    {
        $adjustmentId = (int) DB::table('adjustments')->insertGetId([
            'location_id'             => $this->locationId,
            'created_by'              => $this->userId,
            'approved_by'             => $this->userId,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $type,
            'total_variance_positive' => 0,
            'total_variance_negative' => $deducted,
            'status_id'               => 20,
            'created_at'              => now(),
            'updated_at'              => now(),
        ], 'adjustment_id');

        DB::table('adjustment_details')->insert([
            'adjustment_id'   => $adjustmentId,
            'product_id'      => $productId,
            'add_quantity'    => 0,
            'deduct_quantity' => $deducted,
            'reason'          => $type === 'damage' ? 'damaged' : 'not_found',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $adjustmentId;
    }

    private function makeIncident(
        int $productId,
        string $reason,
        int $reportedQty,
        int $statusId,
        ?int $adjustmentId = null,
        ?int $correctionId = null
    ): int {
        return (int) DB::table('incident_reports')->insertGetId([
            'incident_number' => 'INC-' . strtoupper(substr(uniqid(), -8)),
            'source_type'     => 'issuance',
            'source_id'       => 1,
            'location_id'     => $this->locationId,
            'product_id'      => $productId,
            'reason'          => $reason,
            'quantity'        => $reportedQty,
            'narrative'       => 'Test incident',
            'reported_by'     => $this->userId,
            'status_id'       => $statusId,
            'adjustment_id'   => $adjustmentId,
            'correction_id'   => $correctionId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'incident_id');
    }

    public function test_wrong_type_incident_contributes_zero_loss(): void
    {
        $productId = $this->makeProduct();
        $this->makeIncident($productId, 'wrong_type', 10, $this->statusResolved);

        $result = $this->service->profitLoss($this->locationId, null);

        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
        $this->assertSame(0, $result['profitLossStats']['damagedUnits']);
        $this->assertSame(0, $result['profitLossStats']['stolenUnits']);
    }

    public function test_zone_mismatch_incident_contributes_zero_loss(): void
    {
        $productId = $this->makeProduct();
        $this->makeIncident($productId, 'zone_mismatch', 10, $this->statusResolved);

        $result = $this->service->profitLoss($this->locationId, null);

        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
    }

    public function test_dismissed_damaged_incident_contributes_zero_loss(): void
    {
        $productId = $this->makeProduct();
        $this->makeIncident($productId, 'damaged', 10, $this->statusDismissed);

        $result = $this->service->profitLoss($this->locationId, null);

        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
    }

    public function test_confirmed_damaged_incident_is_valued_at_cost(): void
    {
        $productId    = $this->makeProduct(80);
        $adjustmentId = $this->makeAdjustment($productId, 3, 'damage');
        $this->makeIncident($productId, 'damaged', 3, $this->statusResolved, $adjustmentId);

        $result = $this->service->profitLoss($this->locationId, null);

        $this->assertEqualsWithDelta(240.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
        $this->assertSame(3, $result['profitLossStats']['damagedUnits']);
        $this->assertSame(0, $result['profitLossStats']['stolenUnits']);
    }

    public function test_not_found_incident_counts_as_stolen_units(): void
    {
        $productId    = $this->makeProduct(50);
        $adjustmentId = $this->makeAdjustment($productId, 4, 'loss');
        $this->makeIncident($productId, 'not_found', 4, $this->statusResolved, $adjustmentId);

        $result = $this->service->profitLoss($this->locationId, null);

        $this->assertSame(4, $result['profitLossStats']['stolenUnits']);
        $this->assertEqualsWithDelta(200.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
    }

    public function test_overreported_loss_is_valued_at_the_clamped_write_off(): void
    {
        $productId = $this->makeProduct(80);
        $adjustmentId = $this->makeAdjustment($productId, 6, 'loss');
        $this->makeIncident($productId, 'not_found', 100, $this->statusResolved, $adjustmentId);

        $result = $this->service->profitLoss($this->locationId, null);

        // 6 x 80 = 480, NOT 100 x 80 = 8000.
        $this->assertEqualsWithDelta(480.0, $result['profitLossStats']['totalProfitLoss'], 0.01);
        $this->assertSame(6, $result['profitLossStats']['stolenUnits']);
    }

    public function test_open_incident_is_pending_and_excluded_from_the_total(): void
    {
        $productId = $this->makeProduct();
        $this->makeIncident($productId, 'damaged', 9, $this->statusOpen);

        $result = $this->service->profitLoss($this->locationId, null);

        $this->assertSame(1, $result['profitLossStats']['pendingReports']);
        $this->assertEqualsWithDelta(0.0, $result['profitLossStats']['totalProfitLoss'], 0.01);

        $incident = collect($result['recentLossIncidents'])->firstWhere('qty', 9);
        $this->assertNotNull($incident, 'Open incidents still appear in the list.');
        $this->assertSame('pending', $incident['status']);
    }

    public function test_groups_loss_by_location_and_category(): void
    {
        $categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'LossCat-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $productId = (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Categorised Loss',
            'product_type' => 'appliance',
            'category_id'  => $categoryId,
            'unit_price'   => 100,
            'cost_price'   => 25,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');

        $adjustmentId = $this->makeAdjustment($productId, 8, 'damage');
        $this->makeIncident($productId, 'damaged', 8, $this->statusResolved, $adjustmentId);

        $result = $this->service->profitLoss($this->locationId, null);

        $byLocation = collect($result['lossByBranch'])->firstWhere('name', 'Loss Warehouse');
        $this->assertNotNull($byLocation);
        $this->assertEqualsWithDelta(200.0, $byLocation['amount'], 0.01);

        $byCategory = collect($result['lossByCategory'])->first();
        $this->assertNotNull($byCategory);
        $this->assertSame(100, $byCategory['percentage'], 'Largest category scales to 100%.');
    }
}
