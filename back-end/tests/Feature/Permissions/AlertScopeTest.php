<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_branch_manager_alert_counts_are_scoped_to_their_location(): void
    {
        $uid = uniqid();

        $locA = (int) DB::table('locations')->insertGetId([
            'location_code' => 'ALA-' . $uid,
            'location_name' => 'Alert Loc A ' . $uid,
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $locB = (int) DB::table('locations')->insertGetId([
            'location_code' => 'ALB-' . $uid,
            'location_name' => 'Alert Loc B ' . $uid,
            'location_type' => 'branch_office',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Get an existing admin user id for requested_by FK
        $adminUserId = User::where('role_id', 1)->value('user_id');
        $this->assertNotNull($adminUserId, 'A seeded admin user is required for the requested_by FK.');

        // Insert a pending PR (status_id 13) at each location
        $insertPr = function (int $locationId) use ($adminUserId): void {
            DB::table('purchase_requests')->insert([
                'location_id'     => $locationId,
                'pr_number'       => 'PR-ALERT-' . uniqid(),
                'request_date'    => now()->toDateString(),
                'needed_by_date'  => now()->addDays(7)->toDateString(),
                'estimated_total' => 100.00,
                'status_id'       => 13,
                'requested_by'    => $adminUserId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        };

        $insertPr($locA);
        $insertPr($locB);

        // Create a branch_manager (role_id 3) assigned to locA
        $bm = User::create([
            'first_name'    => 'Alert',
            'last_name'     => 'BM',
            'username'      => 'alert_bm_' . $uid,
            'email'         => 'alert_bm_' . $uid . '@rbac.test',
            'password_hash' => bcrypt('x'),
            'role_id'       => 3,
            'location_id'   => $locA,
        ]);

        $admin = User::where('role_id', 1)->firstOrFail();

        // Fetch alert settings for the BM (scoped) and admin (global)
        $bmResp = $this->actingAs($bm, 'sanctum')->getJson('/api/alerts/settings');
        $bmResp->assertStatus(200);

        $adminResp = $this->actingAs($admin, 'sanctum')->getJson('/api/alerts/settings');
        $adminResp->assertStatus(200);

        // Response shape: { success: true, data: { badge_count: N, pending_pr_count: N, ... } }
        $bmBadge    = (int) data_get($bmResp->json(), 'data.badge_count', 0);
        $adminBadge = (int) data_get($adminResp->json(), 'data.badge_count', 0);

        // Scoped BM badge must not exceed the global admin badge
        $this->assertLessThanOrEqual(
            $adminBadge,
            $bmBadge,
            "Scoped BM badge ({$bmBadge}) must not exceed the global admin badge ({$adminBadge})."
        );

        // Stronger: isolate pending PRs via DB to confirm scoping is meaningful
        $prAll = (int) DB::table('purchase_requests')->where('status_id', 13)->count();
        $prA   = (int) DB::table('purchase_requests')->where('status_id', 13)->where('location_id', $locA)->count();

        $this->assertGreaterThan(
            $prA,
            $prAll,
            'Sanity: there must be pending PRs outside locA so the scoping is meaningful.'
        );

        // Also assert that the BM's pending_pr_count equals exactly the PRs at locA
        $bmPrCount = (int) data_get($bmResp->json(), 'data.pending_pr_count', 0);
        $this->assertSame(
            $prA,
            $bmPrCount,
            "BM pending_pr_count ({$bmPrCount}) should equal PRs at locA ({$prA}), not the global count ({$prAll})."
        );
    }
}
