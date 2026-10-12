<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseRequest;

/**
 * PR approval after budget management was removed: a pending PR approves on its
 * own merit, with no spend ceiling. Airking buys on payment terms, so there is
 * nothing to check the estimated total against.
 */
class PrApproveTest extends ProcurementTestCase
{
    public function test_admin_approves_a_pending_pr(): void
    {
        $admin = $this->makeUser('admin');
        $pr    = $this->makePr(4000, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertStatus(200);

        $fresh = PurchaseRequest::find($pr->pr_id);
        $this->assertSame(14, (int) $fresh->status_id);
        $this->assertSame((int) $admin->user_id, (int) $fresh->approved_by);
        $this->assertNotNull($fresh->approval_date);
    }

    /**
     * The old flow refused any PR without a budget attached. Nothing about the
     * amount can block approval now — only the PR's own state can.
     */
    public function test_a_large_estimated_total_does_not_block_approval(): void
    {
        $admin = $this->makeUser('admin');
        $pr    = $this->makePr(99999999, 13);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertStatus(200);

        $this->assertSame(14, (int) PurchaseRequest::find($pr->pr_id)->status_id);
    }

    public function test_a_non_pending_pr_cannot_be_approved(): void
    {
        $admin = $this->makeUser('admin');
        $pr    = $this->makePr(4000, 14); // already approved

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/purchase-requests/{$pr->pr_id}/approve")
            ->assertStatus(422);
    }

    public function test_approving_a_missing_pr_is_a_404(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/purchase-requests/999999/approve')
            ->assertStatus(404);
    }
}
