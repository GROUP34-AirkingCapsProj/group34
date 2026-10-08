<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\UserPermissionOverride;
use App\Support\PermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RbacInfraTest extends TestCase
{
    use DatabaseTransactions;

    // -------------------------------------------------------------------------
    // Helper: seed the minimum rows needed for a user with a role
    // -------------------------------------------------------------------------

    private function createUserWithRole(string $roleName, string $suffix): array
    {
        $roleId = DB::table('roles')->insertGetId([
            'role_name'   => $roleName . '_' . $suffix,
            'description' => 'Test role ' . $suffix,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], 'role_id');

        $statusId = DB::table('status_lookup')->insertGetId([
            'status_name'     => 'active_' . $suffix,
            'status_category' => 'user',
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'status_id');

        $userId = DB::table('users')->insertGetId([
            'first_name'    => 'Test',
            'last_name'     => 'User',
            'username'      => 'user_' . $suffix,
            'email'         => $suffix . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'status_id'     => $statusId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'user_id');

        return ['user_id' => $userId, 'role_id' => $roleId];
    }

    // =========================================================================
    // (a) Tables exist with key columns
    // =========================================================================

    public function test_permissions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('permissions', ['permission_id', 'feature', 'action', 'description']),
            'permissions table is missing expected columns'
        );
    }

    public function test_role_permissions_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('role_permissions', ['id', 'role_id', 'permission_id', 'is_granted']),
            'role_permissions table is missing expected columns'
        );
    }

    public function test_user_permission_overrides_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('user_permission_overrides', [
                'id', 'user_id', 'permission_id', 'is_granted',
                'granted_by', 'granted_at', 'revoked_at', 'reason',
            ]),
            'user_permission_overrides table is missing expected columns'
        );
    }

    public function test_permission_audit_logs_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('permission_audit_logs', [
                'id', 'changed_by', 'target_user_id', 'target_role_id',
                'permission_id', 'old_value', 'new_value',
                'change_type', 'reason', 'changed_at',
            ]),
            'permission_audit_logs table is missing expected columns'
        );
    }

    // =========================================================================
    // (b) PermissionResolver: canUser with role defaults
    // =========================================================================

    public function test_can_user_returns_true_for_granted_role_permission(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('analyst', $uid);

        $permission = Permission::create([
            'feature'     => 'inventory',
            'action'      => 'view',
            'description' => 'View inventory',
        ]);

        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => true,
        ]);

        $resolver = new PermissionResolver();
        $this->assertTrue($resolver->canUser($userId, 'inventory', 'view'));
    }

    public function test_can_user_returns_false_for_denied_role_permission(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('analyst', $uid);

        $permission = Permission::create([
            'feature' => 'reports',
            'action'  => 'export',
        ]);

        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
        ]);

        $resolver = new PermissionResolver();
        $this->assertFalse($resolver->canUser($userId, 'reports', 'export'));
    }

    public function test_can_user_returns_false_for_unknown_permission(): void
    {
        $uid = uniqid();
        ['user_id' => $userId] = $this->createUserWithRole('analyst', $uid);

        $resolver = new PermissionResolver();
        $this->assertFalse($resolver->canUser($userId, 'nonexistent', 'action'));
    }

    // =========================================================================
    // (b2) Admin bypass — System Administrator always has full access
    // =========================================================================

    /** Create a user whose role is the canonical 'admin' slug (reusing it if seeded). */
    private function createAdminUser(string $suffix): array
    {
        $roleId = DB::table('roles')->where('role_name', 'admin')->value('role_id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'role_name'   => 'admin',
                'description' => 'System Administrator',
                'created_at'  => now(),
                'updated_at'  => now(),
            ], 'role_id');
        }

        $statusId = DB::table('status_lookup')->insertGetId([
            'status_name'     => 'active_' . $suffix,
            'status_category' => 'user',
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'status_id');

        $userId = DB::table('users')->insertGetId([
            'first_name'    => 'Admin',
            'last_name'     => 'User',
            'username'      => 'admin_' . $suffix,
            'email'         => 'admin_' . $suffix . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'status_id'     => $statusId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'user_id');

        return ['user_id' => $userId, 'role_id' => $roleId];
    }

    public function test_admin_bypass_grants_even_when_role_permission_is_denied(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createAdminUser($uid);

        $permission = Permission::create(['feature' => 'roles', 'action' => 'read']);

        // Explicitly deny at the role level — admin bypass must still grant.
        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
        ]);

        $resolver = new PermissionResolver();
        $this->assertTrue($resolver->canUser($userId, 'roles', 'read'));
    }

    public function test_admin_bypass_grants_even_when_active_override_denies(): void
    {
        $uid = uniqid();
        ['user_id' => $userId] = $this->createAdminUser($uid);

        // Feature name is a fictional stand-in for infra testing, deliberately not
        // one of PermissionSeeder::matrix()'s real feature/action pairs — using a
        // real one (e.g. 'system_configuration') collides with the persistently
        // seeded permissions row and fails with a unique-constraint violation.
        $permission = Permission::create(['feature' => 'sysconf_infra_test', 'action' => 'update']);

        UserPermissionOverride::create([
            'user_id'       => $userId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
            'revoked_at'    => null,
        ]);

        $resolver = new PermissionResolver();
        $this->assertTrue($resolver->canUser($userId, 'sysconf_infra_test', 'update'));
    }

    public function test_non_admin_is_not_bypassed(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('analyst', $uid);

        // See note above: a fictional feature name to avoid colliding with the
        // persistently seeded permissions row for a real matrix feature.
        $permission = Permission::create(['feature' => 'sysconf_infra_test', 'action' => 'read']);
        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
        ]);

        $resolver = new PermissionResolver();
        $this->assertFalse($resolver->canUser($userId, 'sysconf_infra_test', 'read'));
    }

    // =========================================================================
    // (c) Active override flips the result
    // =========================================================================

    public function test_active_override_flips_denied_role_default_to_granted(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('staff', $uid);

        $permission = Permission::create([
            'feature' => 'orders',
            'action'  => 'approve',
        ]);

        // Role default: denied
        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
        ]);

        // Active override: granted
        UserPermissionOverride::create([
            'user_id'       => $userId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => true,
            'revoked_at'    => null,
        ]);

        $resolver = new PermissionResolver();
        $this->assertTrue($resolver->canUser($userId, 'orders', 'approve'));
    }

    public function test_active_override_flips_granted_role_default_to_denied(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('staff', $uid);

        $permission = Permission::create([
            'feature' => 'orders',
            'action'  => 'delete',
        ]);

        // Role default: granted
        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => true,
        ]);

        // Active override: denied
        UserPermissionOverride::create([
            'user_id'       => $userId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
            'revoked_at'    => null,
        ]);

        $resolver = new PermissionResolver();
        $this->assertFalse($resolver->canUser($userId, 'orders', 'delete'));
    }

    public function test_revoked_override_does_not_flip_role_default(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('staff', $uid);

        $permission = Permission::create([
            'feature' => 'products',
            'action'  => 'edit',
        ]);

        // Role default: granted
        RolePermission::create([
            'role_id'       => $roleId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => true,
        ]);

        // Revoked override (revoked_at set) — should be ignored
        UserPermissionOverride::create([
            'user_id'       => $userId,
            'permission_id' => $permission->permission_id,
            'is_granted'    => false,
            'revoked_at'    => now(),
        ]);

        $resolver = new PermissionResolver();
        // Role default should win since override is revoked
        $this->assertTrue($resolver->canUser($userId, 'products', 'edit'));
    }

    // =========================================================================
    // (d) effectivePermissionsFor returns merged map
    // =========================================================================

    public function test_effective_permissions_returns_merged_role_and_override_map(): void
    {
        $uid = uniqid();
        ['user_id' => $userId, 'role_id' => $roleId] = $this->createUserWithRole('manager', $uid);

        $permA = Permission::create(['feature' => 'stock',   'action' => 'view']);
        $permB = Permission::create(['feature' => 'stock',   'action' => 'edit']);
        $permC = Permission::create(['feature' => 'reports', 'action' => 'view']);

        // Role defaults: A=true, B=false
        RolePermission::create(['role_id' => $roleId, 'permission_id' => $permA->permission_id, 'is_granted' => true]);
        RolePermission::create(['role_id' => $roleId, 'permission_id' => $permB->permission_id, 'is_granted' => false]);

        // Active override: flip B to true, add C as true
        UserPermissionOverride::create([
            'user_id'       => $userId,
            'permission_id' => $permB->permission_id,
            'is_granted'    => true,
            'revoked_at'    => null,
        ]);
        UserPermissionOverride::create([
            'user_id'       => $userId,
            'permission_id' => $permC->permission_id,
            'is_granted'    => true,
            'revoked_at'    => null,
        ]);

        $resolver = new PermissionResolver();
        $map = $resolver->effectivePermissionsFor($userId);

        $this->assertArrayHasKey('stock.view', $map);
        $this->assertArrayHasKey('stock.edit', $map);
        $this->assertArrayHasKey('reports.view', $map);

        $this->assertTrue($map['stock.view'],   'stock.view should be true (role default)');
        $this->assertTrue($map['stock.edit'],   'stock.edit should be true (override flipped)');
        $this->assertTrue($map['reports.view'], 'reports.view should be true (override only)');
    }
}
