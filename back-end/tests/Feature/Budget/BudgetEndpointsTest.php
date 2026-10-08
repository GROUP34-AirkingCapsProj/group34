<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;
use Illuminate\Support\Facades\DB;

class BudgetEndpointsTest extends BudgetTestCase
{
    private function anyProductId(): int
    {
        return (int) (DB::table('products')->value('product_id')
            ?? DB::table('products')->insertGetId([
                'product_name' => 'Budget Test Product ' . uniqid(),
                'product_code' => 'BTP-' . strtoupper(substr(uniqid(), -6)),
                'product_type' => 'installation_material',
                'created_at'   => now(),
                'updated_at'   => now(),
            ], 'product_id'));
    }

    private function committedPo(\App\Models\BudgetAllocation $budget, float $amount): \App\Models\PurchaseOrder
    {
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, $amount, 7);
        DB::table('purchase_order_details')->insert([
            'po_id'            => $po->po_id,
            'product_id'       => $this->anyProductId(),
            'quantity_ordered' => 1,
            'unit_price'       => $amount,
            'subtotal'         => $amount,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        app(BudgetService::class)->commitPo($po->fresh()->load('details'));

        return $po->fresh();
    }

    public function test_store_rejects_non_warehouse_location(): void
    {
        $admin = $this->makeUser('admin');
        $showroomId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'SR-' . uniqid(),
            'location_name' => 'Showroom X',
            'location_type' => 'showroom',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/budget-allocations', [
            'location_id'  => $showroomId,
            'period_label' => '2026-July',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total_budget' => 1000,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('warehouse', strtolower($response->json('message')));
        $this->assertSame(0, \App\Models\BudgetAllocation::where('location_id', $showroomId)->count());
    }

    public function test_store_allows_warehouse_location(): void
    {
        // BudgetTestCase::$locationId is a warehouse — the endpoint should accept it.
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/budget-allocations', [
            'location_id'  => $this->locationId,
            'period_label' => '2026-Sept',
            'period_start' => '2026-09-01',
            'period_end'   => '2026-09-30',
            'total_budget' => 5000,
        ])->assertSuccessful();
    }

    public function test_update_total_routes_through_adjustment_journal(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/budget-allocations/{$budget->budget_id}", ['total_budget' => 15000])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('15000.00', (string) $budget->total_budget);
        $this->assertSame(1, BudgetTransaction::where('budget_id', $budget->budget_id)
            ->where('txn_type', 'adjustment')->count());
    }

    public function test_update_rejects_total_below_committed_plus_spent(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['committed' => 4000, 'spent' => 3000]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/budget-allocations/{$budget->budget_id}", ['total_budget' => 5000])
            ->assertStatus(422);

        $this->assertSame('10000.00', (string) $budget->fresh()->total_budget);
    }

    public function test_destroy_blocked_while_commitments_outstanding(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $this->committedPo($budget, 4000); // PO approval reserves against this budget

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertStatus(422);

        $this->assertNull($budget->fresh()->deleted_at);
    }

    public function test_destroy_allowed_when_nothing_committed(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['spent' => 2000]); // spent is fine, committed is not

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertSuccessful();
    }

    public function test_force_destroy_blocked_while_ledger_rows_exist(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $po = $this->committedPo($budget, 4000);
        app(BudgetService::class)->releaseCommitment($po, 'PO rejected'); // committed back to 0, but ledger has history
        $budget->refresh();
        $budget->delete(); // archive it first so forceDestroy can find it

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}/force")
            ->assertStatus(422);

        $this->assertSame(2, BudgetTransaction::where('budget_id', $budget->budget_id)->count());
    }

    public function test_transactions_endpoint_returns_ledger_with_document_refs(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 0, 14);
        $po = $this->makePo($pr, 4000, 7);
        DB::table('purchase_order_details')->insert([
            'po_id'            => $po->po_id,
            'product_id'       => $this->anyProductId(),
            'quantity_ordered' => 1,
            'unit_price'       => 4000,
            'subtotal'         => 4000,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
        app(BudgetService::class)->commitPo($po->fresh()->load('details'), $admin->user_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/budget-allocations/{$budget->budget_id}/transactions");

        $response->assertSuccessful();
        $row = $response->json('data.0');
        $this->assertSame('commit', $row['txn_type']);
        $this->assertSame($pr->pr_number, $row['purchase_request']['pr_number']);
        $this->assertSame($po->po_number, $row['purchase_order']['po_number']);
        $this->assertSame('Test', $row['performer']['first_name']);
    }

    public function test_transactions_endpoint_readable_by_inventory_analyst(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000);

        $this->actingAs($ia, 'sanctum')
            ->getJson("/api/budget-allocations/{$budget->budget_id}/transactions")
            ->assertSuccessful();
    }

    public function test_store_tops_up_existing_budget_for_same_period(): void
    {
        $admin = $this->makeUser('admin');
        // An existing budget for July at this location.
        $existing = $this->makeBudget(1000, [
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'category_id'  => null,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/budget-allocations', [
            'location_id'  => $this->locationId,
            'period_label' => '2026-July',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total_budget' => 500,
        ]);

        $response->assertSuccessful();
        // No second row created — the July budget was topped up to 1500.
        $this->assertSame(1, \App\Models\BudgetAllocation::where('location_id', $this->locationId)
            ->whereDate('period_start', '2026-07-01')->count());
        $existing->refresh();
        $this->assertSame('1500.00', (string) $existing->total_budget);
        // Journaled as an adjustment.
        $this->assertSame(1, BudgetTransaction::where('budget_id', $existing->budget_id)
            ->where('txn_type', 'adjustment')->count());
    }

    public function test_store_creates_new_budget_for_a_different_period(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeBudget(1000, ['period_start' => '2026-07-01', 'period_end' => '2026-07-31']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/budget-allocations', [
            'location_id'  => $this->locationId,
            'period_label' => '2026-August',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'total_budget' => 2000,
        ])->assertSuccessful();

        $this->assertSame(2, \App\Models\BudgetAllocation::where('location_id', $this->locationId)->count());
    }
}
