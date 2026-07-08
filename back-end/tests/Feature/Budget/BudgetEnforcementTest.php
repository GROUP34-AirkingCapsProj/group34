<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class BudgetEnforcementTest extends BudgetTestCase
{
    private function makeProduct(): int
    {
        return (int) DB::table('products')->insertGetId([
            'product_code' => 'TST-' . uniqid(),
            'product_name' => 'Budget Enforcement Product',
            'product_type' => 'installation_material',
            'unit_price'   => 100,
            'cost_price'   => 80,
            'status_id'    => 3,
            'created_at'   => now(),
            'updated_at'   => now(),
        ], 'product_id');
    }

    private function prPayload(array $overrides = []): array
    {
        return array_merge([
            'location_id'    => $this->locationId,
            'needed_by_date' => today()->addDays(7)->toDateString(),
            'details'        => [[
                'product_id'      => $this->makeProduct(),
                'quantity'        => 10,
                'estimated_price' => 500, // estimated_total = 5000
            ]],
        ], $overrides);
    }

    public function test_store_rejects_pr_without_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload());

        $response->assertStatus(422);
        // ApiResponse::validationError shape — assert the field is named, not the exact envelope.
        $this->assertArrayHasKey('budget_id', $response->json('errors') ?? $response->json('data') ?? []);
    }

    public function test_store_rejects_budget_from_other_location(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $otherLocation = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-BR-' . uniqid(),
            'location_name' => 'Other Branch',
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
        $budget = $this->makeBudget(10000, ['location_id' => $otherLocation]);

        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]))
            ->assertStatus(422);
    }

    public function test_store_rejects_inactive_budget_period(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000, [
            'period_start' => today()->subDays(60)->toDateString(),
            'period_end'   => today()->subDays(30)->toDateString(),
        ]);

        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]))
            ->assertStatus(422);
    }

    public function test_store_rejects_over_budget_total(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(4000); // payload estimates 5000

        $response = $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]));

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds available budget', $response->json('message'));
    }

    public function test_store_accepts_pr_within_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000);

        $this->actingAs($ia, 'sanctum')
            ->postJson('/api/purchase-requests', $this->prPayload(['budget_id' => $budget->budget_id]))
            ->assertStatus(201);
    }

    public function test_approve_commits_via_ledger(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 5000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('5000.00', (string) $budget->committed);
        $this->assertSame(1, BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'commit')->count());
    }

    public function test_approve_blocks_when_funds_consumed_since_submission(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 6000, 13);

        // Another PR ate the funds between submission and approval.
        $rival = $this->makePr($budget, 7000, 14);
        app(BudgetService::class)->commit($rival);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve");

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds available budget', $response->json('message'));
        $this->assertSame(13, $pr->fresh()->status_id); // still pending — approval rolled back
    }

    public function test_approve_blocks_legacy_pr_without_budget(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 5000, 13, ['budget_id' => null]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertStatus(422);
    }

    public function test_restock_create_pr_attaches_active_warehouse_budget(): void
    {
        $admin = $this->makeUser('admin');
        $whId = (int) DB::table('locations')->where('location_type', 'warehouse')
            ->orderBy('location_id')->value('location_id');
        DB::table('budget_allocations')->where('location_id', $whId)
            ->whereNull('deleted_at')
            ->where('period_start', '<=', today())->where('period_end', '>=', today())
            ->update(['deleted_at' => now()]);
        $budget = $this->makeBudget(100000, ['location_id' => $whId]);
        $productId = $this->makeProduct();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/restock/create-pr', [
                'items' => [['product_id' => $productId, 'quantity' => 5]],
            ]);

        $response->assertStatus(201);
        $prId = $response->json('data.pr_id');
        $this->assertEquals($budget->budget_id, \App\Models\PurchaseRequest::find($prId)->budget_id);
    }

    public function test_restock_create_pr_blocks_without_active_budget(): void
    {
        $admin = $this->makeUser('admin');
        $productId = $this->makeProduct();
        // Ensure NO active budget covers today for the first warehouse.
        $whId = (int) DB::table('locations')->where('location_type', 'warehouse')
            ->orderBy('location_id')->value('location_id');
        DB::table('budget_allocations')->where('location_id', $whId)
            ->whereNull('deleted_at')
            ->where('period_start', '<=', today())->where('period_end', '>=', today())
            ->update(['deleted_at' => now()]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/restock/create-pr', [
                'items' => [['product_id' => $productId, 'quantity' => 5]],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('budget', strtolower($response->json('message')));
    }

    public function test_recommendations_payload_includes_active_budget_summary(): void
    {
        $admin = $this->makeUser('admin');
        $whId = (int) DB::table('locations')->where('location_type', 'warehouse')
            ->orderBy('location_id')->value('location_id');
        // Archive any pre-existing active budgets for a deterministic result.
        DB::table('budget_allocations')->where('location_id', $whId)
            ->whereNull('deleted_at')
            ->where('period_start', '<=', today())->where('period_end', '>=', today())
            ->update(['deleted_at' => now()]);
        $budget = $this->makeBudget(50000, ['location_id' => $whId, 'committed' => 10000]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/restock/recommendations');

        $response->assertSuccessful();
        $this->assertEquals($budget->budget_id, $response->json('data.budget.budget_id'));
        $this->assertEquals(40000, $response->json('data.budget.available'));
    }
}
