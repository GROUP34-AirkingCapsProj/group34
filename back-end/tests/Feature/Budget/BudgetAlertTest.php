<?php

namespace Tests\Feature\Budget;

use App\Events\BudgetChanged;
use Illuminate\Support\Facades\Event;

class BudgetAlertTest extends BudgetTestCase
{
    public function test_auditor_sees_budget_verification_alert(): void
    {
        $auditor = $this->makeUser('auditor');
        $this->makeBudget(1000, ['verification_status' => 'pending']);

        $res = $this->actingAs($auditor, 'sanctum')->getJson('/api/alerts/settings');
        $res->assertSuccessful();

        $alerts = collect($res->json('data.alerts'));
        $this->assertContains('budget_verification', $alerts->pluck('type')->all());
        $this->assertGreaterThanOrEqual(1, (int) $res->json('data.badge_count'));

        $budgetAlert = $alerts->firstWhere('type', 'budget_verification');
        $this->assertStringContainsString('verification', strtolower($budgetAlert['label']));
        $this->assertSame('/admin/budget-management', $budgetAlert['link']);
    }

    public function test_verified_budget_produces_no_verification_alert(): void
    {
        $auditor = $this->makeUser('auditor');
        $this->makeBudget(1000); // verified by default

        $res = $this->actingAs($auditor, 'sanctum')->getJson('/api/alerts/settings');
        $res->assertSuccessful();

        $types = collect($res->json('data.alerts'))->pluck('type')->all();
        $this->assertNotContains('budget_verification', $types);
    }

    public function test_non_verifier_does_not_see_budget_verification_alert(): void
    {
        $wp = $this->makeUser('warehouse_personnel');
        $this->makeBudget(1000, ['verification_status' => 'pending']);

        $res = $this->actingAs($wp, 'sanctum')->getJson('/api/alerts/settings');
        $res->assertSuccessful();

        $types = collect($res->json('data.alerts'))->pluck('type')->all();
        $this->assertNotContains('budget_verification', $types);
    }

    public function test_creating_a_budget_broadcasts_verification_pending(): void
    {
        Event::fake([BudgetChanged::class]);
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')->postJson('/api/budget-allocations', [
            'location_id'  => $this->locationId,
            'period_label' => '2026-Oct',
            'period_start' => '2026-10-01',
            'period_end'   => '2026-10-31',
            'total_budget' => 5000,
        ])->assertSuccessful();

        Event::assertDispatched(BudgetChanged::class, fn ($e) =>
            $e->type === 'verification_pending' && $e->locationId === $this->locationId);
    }
}
