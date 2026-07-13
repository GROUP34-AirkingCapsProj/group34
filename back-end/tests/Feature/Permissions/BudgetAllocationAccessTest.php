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
 * Budget allocation CRUD is delegable via permission:budget_verification,{create,
 * update,archive}. Verifies the create gate: revoked → 403; granted/admin pass the
 * middleware (they reach validation instead of a 403).
 */
class BudgetAllocationAccessTest extends TestCase
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

    private function createPermission(): Permission
    {
        return Permission::firstOrCreate(
            ['feature' => 'budget_verification', 'action' => 'create'],
            ['description' => 'Create budget allocations']
        );
    }

    public function test_non_admin_without_create_grant_is_forbidden(): void
    {
        $user = $this->makeUser('branch_manager');
        $perm = $this->createPermission();
        RolePermission::updateOrCreate(
            ['role_id' => $user->role_id, 'permission_id' => $perm->permission_id],
            ['is_granted' => false]
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/budget-allocations', [])
            ->assertStatus(403);
    }

    public function test_non_admin_with_create_grant_passes_the_gate(): void
    {
        $user = $this->makeUser('branch_manager');
        $perm = $this->createPermission();
        RolePermission::updateOrCreate(
            ['role_id' => $user->role_id, 'permission_id' => $perm->permission_id],
            ['is_granted' => true]
        );

        $status = $this->actingAs($user, 'sanctum')
            ->postJson('/api/budget-allocations', [])
            ->status();

        // Passed the permission middleware — reaches validation, not a 403.
        $this->assertNotSame(403, $status);
    }

    public function test_admin_passes_the_gate_without_a_grant_row(): void
    {
        $admin = $this->makeUser('admin');
        $this->createPermission(); // no admin grant row — bypass should allow through

        $status = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/budget-allocations', [])
            ->status();

        $this->assertNotSame(403, $status);
    }
}
