<?php

namespace Tests\Feature\Budget;

use App\Events\BudgetChanged;
use Illuminate\Support\Facades\Event;

class BudgetVerificationTest extends BudgetTestCase
{
    public function test_auditor_verifies_budget(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify", ['note' => 'Checked against Q3 plan'])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('verified', $budget->verification_status);
        $this->assertSame($auditor->user_id, (int) $budget->verified_by);
        $this->assertNotNull($budget->verified_at);
        $this->assertSame('Checked against Q3 plan', $budget->verification_note);
    }

    public function test_auditor_rejects_budget_with_required_reason(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/reject", ['reason' => 'Amount exceeds approved plan'])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('rejected', $budget->verification_status);
        $this->assertSame('Amount exceeds approved plan', $budget->verification_note);
    }

    public function test_cannot_verify_budget_you_created(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, [
            'verification_status' => 'pending',
            'created_by' => $auditor->user_id,
        ]);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertStatus(422);
    }

    public function test_double_verify_is_noop_success(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000); // already verified

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertSuccessful();

        $this->assertSame('verified', $budget->fresh()->verification_status);
    }

    public function test_verify_fires_realtime_signal(): void
    {
        Event::fake([BudgetChanged::class]);
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertSuccessful();

        Event::assertDispatched(BudgetChanged::class, fn ($e) =>
            $e->locationId === (int) $budget->location_id && $e->type === 'verification_changed');
    }

    public function test_admin_with_per_user_override_can_verify_budget(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000, ['verification_status' => 'pending']);

        $perm = \App\Models\Permission::where('feature', 'budget_verification')
            ->where('action', 'verify')->firstOrFail();
        \App\Models\UserPermissionOverride::create([
            'user_id'       => $admin->user_id,
            'permission_id' => $perm->permission_id,
            'is_granted'    => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/budget-allocations/{$budget->budget_id}/verify")
            ->assertSuccessful();

        $this->assertSame('verified', $budget->fresh()->verification_status);
    }
}
