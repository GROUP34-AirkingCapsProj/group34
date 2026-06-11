<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Purchase Request approval is gated by permission:purchase_request_approval,approve.
 * Default grants SA only; granting another role lets it through with no code change.
 * (Uses a non-existent PR id — the permission middleware runs before the controller,
 * so the gate is asserted independently of PR fixtures: blocked = 403, allowed = not 403.)
 */
class PurchaseRequestApprovalSliceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
    }

    private function user(int $roleId): User
    {
        return User::create([
            'username' => 'pr_' . uniqid(), 'first_name' => 'P', 'last_name' => 'R',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function grantApproval(User $u): void
    {
        $permId = (int) Permission::where('feature', 'purchase_request_approval')
            ->where('action', 'approve')->value('permission_id');
        UserPermissionOverride::create([
            'user_id' => $u->user_id, 'permission_id' => $permId,
            'is_granted' => true, 'granted_by' => $u->user_id, 'granted_at' => now(),
        ]);
    }

    public function test_purchase_request_approval_permission_is_seeded(): void
    {
        $this->assertNotNull(
            Permission::where('feature', 'purchase_request_approval')->where('action', 'approve')->first()
        );
    }

    public function test_inventory_analyst_blocked_from_pr_approve_by_default(): void
    {
        $ia = $this->user(2);
        $this->actingAs($ia, 'sanctum')
            ->patchJson('/api/purchase-requests/999999/approve', [])
            ->assertStatus(403);
    }

    public function test_inventory_analyst_passes_pr_approve_gate_after_grant(): void
    {
        $ia = $this->user(2);
        $this->grantApproval($ia);

        // Past the permission gate (controller may 404 on the missing PR, but NOT 403).
        $res = $this->actingAs($ia, 'sanctum')->patchJson('/api/purchase-requests/999999/approve', []);
        $this->assertNotEquals(403, $res->getStatusCode());
    }

    public function test_admin_passes_pr_approve_gate_by_default(): void
    {
        $admin = $this->user(1);
        $res = $this->actingAs($admin, 'sanctum')->patchJson('/api/purchase-requests/999999/approve', []);
        $this->assertNotEquals(403, $res->getStatusCode());
    }
}
