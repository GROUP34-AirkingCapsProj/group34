<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;

class BudgetEndpointsTest extends BudgetTestCase
{
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
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);

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
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        app(BudgetService::class)->releaseOutstanding($pr, 'PR archived'); // committed back to 0, but ledger has history
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
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr, $admin->user_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/budget-allocations/{$budget->budget_id}/transactions");

        $response->assertSuccessful();
        $row = $response->json('data.0');
        $this->assertSame('commit', $row['txn_type']);
        $this->assertSame($pr->pr_number, $row['purchase_request']['pr_number']);
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
}
