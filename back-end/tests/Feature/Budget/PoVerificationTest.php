<?php

namespace Tests\Feature\Budget;

use App\Events\PurchaseOrderChanged;
use Illuminate\Support\Facades\Event;

class PoVerificationTest extends BudgetTestCase
{
    public function test_auditor_verifies_po(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify", ['note' => 'Matches PR + supplier quote'])
            ->assertSuccessful();

        $po->refresh();
        $this->assertSame('verified', $po->verification_status);
        $this->assertSame($auditor->user_id, (int) $po->verified_by);
        $this->assertNotNull($po->verified_at);
    }

    public function test_auditor_rejects_po_requires_reason(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", ['reason' => 'Supplier not accredited'])
            ->assertSuccessful();

        $po->refresh();
        $this->assertSame('rejected', $po->verification_status);
        $this->assertSame(7, (int) $po->status_id);
    }

    public function test_cannot_reject_already_approved_po(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 10); // Authorized

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/reject", ['reason' => 'too late'])
            ->assertStatus(422);
    }

    public function test_cannot_verify_po_you_created(): void
    {
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);
        $po->update(['created_by' => $auditor->user_id]);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify")
            ->assertStatus(422);
    }

    public function test_po_verify_fires_realtime_signal(): void
    {
        Event::fake([PurchaseOrderChanged::class]);
        $auditor = $this->makeUser('auditor');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        $po = $this->makePo($pr, 4000, 7);

        $this->actingAs($auditor, 'sanctum')
            ->patchJson("/api/purchase-orders/{$po->po_id}/verify")
            ->assertSuccessful();

        Event::assertDispatched(PurchaseOrderChanged::class, fn ($e) => $e->type === 'verification_changed');
    }
}
