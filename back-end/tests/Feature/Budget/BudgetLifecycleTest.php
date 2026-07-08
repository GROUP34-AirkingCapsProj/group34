<?php

namespace Tests\Feature\Budget;

use App\Models\BudgetTransaction;
use App\Services\BudgetService;

class BudgetLifecycleTest extends BudgetTestCase
{
    public function test_archiving_approved_pr_releases_commitment(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/purchase-requests/{$pr->pr_id}")
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('0.00', (string) $budget->committed);
        $this->assertSoftDeleted('purchase_requests', ['pr_id' => $pr->pr_id]);

        $release = BudgetTransaction::where('pr_id', $pr->pr_id)->where('txn_type', 'release')->first();
        $this->assertNotNull($release);
        $this->assertSame('PR archived', $release->notes);
    }

    public function test_archiving_pending_pr_releases_nothing(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 13);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/purchase-requests/{$pr->pr_id}")
            ->assertSuccessful();

        $this->assertSame(0, BudgetTransaction::where('pr_id', $pr->pr_id)->count());
    }

    public function test_archiving_missing_pr_returns_404(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/purchase-requests/999999')
            ->assertStatus(404);
    }

    public function test_po_completion_via_update_records_spend_and_consumes_commitment(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        $po = $this->makePo($pr, 3800, 7);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 11])
            ->assertSuccessful();

        $budget->refresh();
        $this->assertSame('3800.00', (string) $budget->spent);
        $this->assertSame('0.00', (string) $budget->committed); // 3800 consumed + 200 surplus returned
    }

    public function test_repeated_completion_update_does_not_double_spend(): void
    {
        $admin = $this->makeUser('admin');
        $budget = $this->makeBudget(10000);
        $pr = $this->makePr($budget, 4000, 14);
        app(BudgetService::class)->commit($pr);
        $po = $this->makePo($pr, 4000, 7);
        $pr->update(['converted_to_po_id' => $po->po_id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/purchase-orders/{$po->po_id}", ['status_id' => 11])->assertSuccessful();
        // Simulate the receiving flow (or a re-save) trying to finalize again:
        app(BudgetService::class)->spend($po->fresh()->load('purchaseRequest'));

        $budget->refresh();
        $this->assertSame('4000.00', (string) $budget->spent);
        $this->assertSame(1, BudgetTransaction::where('po_id', $po->po_id)->where('txn_type', 'spend')->count());
    }
}
