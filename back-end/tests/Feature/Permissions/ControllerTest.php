<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $roleId): User
    {
        return User::create([
            'username' => 'ct_' . uniqid(), 'first_name' => 'C', 'last_name' => 'T',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->user(2), 'sanctum')->getJson('/api/role-permissions')->assertStatus(403);
    }

    public function test_admin_lists_and_updates_role_default(): void
    {
        (new PermissionSeeder())->run();
        $admin = $this->user(1);

        $this->actingAs($admin, 'sanctum')->getJson('/api/permissions')
            ->assertOk()->assertJsonStructure(['data' => [['permission_id', 'feature', 'action']]]);

        $permId = Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');

        $this->actingAs($admin, 'sanctum')->putJson('/api/role-permissions', [
            'changes' => [['role_id' => 3, 'permission_id' => $permId, 'is_granted' => true]],
        ])->assertOk();

        $this->assertTrue(RolePermission::where('role_id', 3)->where('permission_id', $permId)->first()->is_granted);
    }

    public function test_admin_sets_user_override_requires_reason(): void
    {
        (new PermissionSeeder())->run();
        $admin = $this->user(1);
        $target = $this->user(3);
        $permId = Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');

        // Missing reason → 422
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'changes' => [['permission_id' => $permId, 'is_granted' => true]],
        ])->assertStatus(422);

        // With reason → 200
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'reason' => 'temporary grant',
            'changes' => [['permission_id' => $permId, 'is_granted' => true]],
        ])->assertOk();
    }

    public function test_admin_views_audit_log(): void
    {
        (new PermissionSeeder())->run();
        $admin = $this->user(1);
        $target = $this->user(3);
        $permId = Permission::where('feature', 'supplier_management')->where('action', 'create')->value('permission_id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'reason' => 'audit me', 'changes' => [['permission_id' => $permId, 'is_granted' => false]],
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->getJson('/api/permission-audit-logs')
            ->assertOk()->assertJsonStructure(['data']);
    }

    public function test_clear_override_via_null_is_granted(): void
    {
        (new \Database\Seeders\PermissionSeeder())->run();
        $admin = $this->user(1);
        $target = $this->user(3);
        $permId = \App\Models\Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');

        // set an override, then clear it with is_granted = null
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'reason' => 'grant', 'changes' => [['permission_id' => $permId, 'is_granted' => true]],
        ])->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$target->user_id}/permissions", [
            'reason' => 'reset', 'changes' => [['permission_id' => $permId, 'is_granted' => null]],
        ])->assertOk();

        $this->assertEquals(0, \App\Models\UserPermissionOverride::where('user_id', $target->user_id)
            ->where('permission_id', $permId)->whereNull('revoked_at')->count());
        $this->assertEquals(1, \App\Models\PermissionAuditLog::where('change_type', 'user_override_clear')
            ->where('permission_id', $permId)->count());
    }

    public function test_unknown_user_returns_404(): void
    {
        (new \Database\Seeders\PermissionSeeder())->run();
        $admin = $this->user(1);
        $permId = \App\Models\Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');
        $this->actingAs($admin, 'sanctum')->putJson('/api/users/99999999/permissions', [
            'reason' => 'x', 'changes' => [['permission_id' => $permId, 'is_granted' => true]],
        ])->assertStatus(404);
    }

    public function test_bogus_role_id_is_422(): void
    {
        (new \Database\Seeders\PermissionSeeder())->run();
        $admin = $this->user(1);
        $permId = \App\Models\Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');
        $this->actingAs($admin, 'sanctum')->putJson('/api/role-permissions', [
            'changes' => [['role_id' => 99999999, 'permission_id' => $permId, 'is_granted' => true]],
        ])->assertStatus(422);
    }

    public function test_admin_role_and_admin_user_are_protected(): void
    {
        (new \Database\Seeders\PermissionSeeder())->run();
        $admin = $this->user(1);
        $otherAdmin = $this->user(1);
        $permId = \App\Models\Permission::where('feature', 'inventory_transfers')->where('action', 'approve')->value('permission_id');

        // editing the admin role's defaults → 403
        $this->actingAs($admin, 'sanctum')->putJson('/api/role-permissions', [
            'changes' => [['role_id' => 1, 'permission_id' => $permId, 'is_granted' => false]],
        ])->assertStatus(403);

        // overriding another admin user's permissions → 403
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$otherAdmin->user_id}/permissions", [
            'reason' => 'nope', 'changes' => [['permission_id' => $permId, 'is_granted' => false]],
        ])->assertStatus(403);
    }
}
