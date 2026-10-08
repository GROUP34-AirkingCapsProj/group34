<?php

namespace Tests\Feature\Budget;

class VerificationPermissionTest extends BudgetTestCase
{
    public function test_auditor_cannot_write_non_verification_endpoint(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);

        $this->actingAs($auditor, 'sanctum')
            ->deleteJson("/api/budget-allocations/{$budget->budget_id}")
            ->assertStatus(403);
    }
}
