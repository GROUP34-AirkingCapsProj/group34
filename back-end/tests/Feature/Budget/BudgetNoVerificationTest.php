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
        $budget = $this->makeBudget(10000);
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

    public function test_resolve_active_budget_returns_the_current_budget(): void
    {
        $budget = $this->makeBudget(10000);

        $resolved = app(\App\Services\BudgetService::class)->resolveActiveBudget($this->locationId);

        $this->assertNotNull($resolved);
        $this->assertSame($budget->budget_id, $resolved->budget_id);
    }

    public function test_the_budget_verify_route_no_longer_exists(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(404);
    }

    public function test_an_auditor_sees_no_budget_verification_alert(): void
    {
        $auditor = $this->makeUser('auditor');
        $this->makeBudget(1000);

        $res = $this->actingAs($auditor, 'sanctum')->getJson('/api/alerts/settings');
        $res->assertSuccessful();

        $types = collect($res->json('data.alerts'))->pluck('type')->all();
        $this->assertNotContains('budget_verification', $types);
    }

    public function test_the_newest_budget_wins_among_overlapping_periods(): void
    {
        $older = $this->makeBudget(10000, [
            'period_start' => today()->subDays(20)->toDateString(),
            'period_end'   => today()->addDays(20)->toDateString(),
        ]);
        $newer = $this->makeBudget(20000, [
            'period_start' => today()->subDays(5)->toDateString(),
            'period_end'   => today()->addDays(5)->toDateString(),
        ]);

        $resolved = app(\App\Services\BudgetService::class)->resolveActiveBudget($this->locationId);

        $this->assertSame($newer->budget_id, $resolved->budget_id);
        $this->assertNotSame($older->budget_id, $resolved->budget_id);
    }
}
