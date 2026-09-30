<?php

namespace Tests\Feature\Budget;

/**
 * Budgets no longer pass through an auditor. An admin's allocation funds
 * requests the moment it exists, and editing its total does not take it back
 * out of service.
 */
class BudgetNoVerificationTest extends BudgetTestCase
{
    public function test_a_freshly_created_budget_funds_a_pr_approval(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);
        $pr = $this->makePr($budget, 4000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();

        $this->assertSame(14, $pr->fresh()->status_id);
    }

    public function test_raising_a_budget_total_does_not_block_the_next_approval(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/budget-allocations/{$budget->budget_id}", ['total_budget' => 15000])
            ->assertSuccessful();

        $pr = $this->makePr($budget->fresh(), 12000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertSuccessful();
    }

    public function test_resolve_active_budget_returns_a_budget_regardless_of_past_status(): void
    {
        $budget = $this->makeBudget(10000, ['verification_status' => 'rejected']);

        $resolved = app(\App\Services\BudgetService::class)->resolveActiveBudget($this->locationId);

        $this->assertNotNull($resolved);
        $this->assertSame($budget->budget_id, $resolved->budget_id);
    }
}
