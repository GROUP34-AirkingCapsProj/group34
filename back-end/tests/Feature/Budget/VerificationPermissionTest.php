<?php

namespace Tests\Feature\Budget;

class VerificationPermissionTest extends BudgetTestCase
{
    // These three assert access decisions that resolve in middleware BEFORE the
    // controller method runs, so they pass in this task even though the budget
    // verify/reject endpoints are implemented in Task 4.

    public function test_inventory_analyst_cannot_verify_budget(): void
    {
        $ia = $this->makeUser('inventory_analyst');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($ia, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(403);
    }

    public function test_admin_cannot_verify_budget_by_default(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(403);
    }

    public function test_auditor_cannot_write_non_verification_endpoint(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);

        $this->actingAs($auditor, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertStatus(403);
    }
}
