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
}
