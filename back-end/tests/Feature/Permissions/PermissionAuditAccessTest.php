<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\StatusLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Permission Audit log viewing is delegable via permission:permission_audit,read.
 * Verifies the gate: revoked → 403, granted → 200, admin always → 200.
 */
class PermissionAuditAccessTest extends TestCase
{
    use DatabaseTransactions;

    private function activeStatusId(): int
    {
        $existing = StatusLookup::first();
        if ($existing) {
            return (int) $existing->status_id;
        }
        return (int) StatusLookup::forceCreate([
            'status_name'     => 'Active',
            'status_category' => 'user',
        ])->status_id;
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
        return User::forceCreate([
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => $roleName . '_' . uniqid() . '@test.local',
            'username'      => $roleName . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $this->activeStatusId(),
        ]);
    }

    private function auditPermission(): Permission
    {
        return Permission::firstOrCreate(
            ['feature' => 'permission_audit', 'action' => 'read'],
            ['description' => 'View permission audit logs']
        );
    }

    public function test_non_admin_without_grant_is_forbidden(): void
    {
        $user = $this->makeUser('branch_manager');
        $perm = $this->auditPermission();
        RolePermission::updateOrCreate(
            ['role_id' => $user->role_id, 'permission_id' => $perm->permission_id],
            ['is_granted' => false]
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/permission-audit-logs')
            ->assertStatus(403);
    }

    public function test_non_admin_with_grant_is_allowed(): void
    {
        $user = $this->makeUser('branch_manager');
        $perm = $this->auditPermission();
        RolePermission::updateOrCreate(
            ['role_id' => $user->role_id, 'permission_id' => $perm->permission_id],
            ['is_granted' => true]
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/permission-audit-logs')
            ->assertStatus(200);
    }

    public function test_admin_is_allowed_even_without_a_grant_row(): void
    {
        $admin = $this->makeUser('admin');
        $this->auditPermission(); // permission exists, but no admin grant row created

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/permission-audit-logs')
            ->assertStatus(200);
    }
}
