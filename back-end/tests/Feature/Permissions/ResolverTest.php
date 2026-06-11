<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\PermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $roleId = 2): User
    {
        return User::create([
            'username' => 'r_' . uniqid(), 'first_name' => 'R', 'last_name' => 'T',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    private function perm(string $f, string $a): Permission
    {
        return Permission::create(['feature' => $f, 'action' => $a, 'description' => 'x']);
    }

    public function test_role_default_grants_when_no_override(): void
    {
        $u = $this->user(2);
        $p = $this->perm('demo', 'create');
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);

        $this->assertTrue(app(PermissionResolver::class)->canUser($u->user_id, 'demo', 'create'));
    }

    public function test_active_override_beats_role_default(): void
    {
        $u = $this->user(2);
        $p = $this->perm('demo', 'create');
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);
        UserPermissionOverride::create([
            'user_id' => $u->user_id, 'permission_id' => $p->permission_id,
            'is_granted' => false, 'granted_by' => $u->user_id, 'granted_at' => now(),
        ]);

        $this->assertFalse(app(PermissionResolver::class)->canUser($u->user_id, 'demo', 'create'));
    }

    public function test_revoked_override_is_ignored_and_role_default_applies(): void
    {
        $u = $this->user(2);
        $p = $this->perm('demo', 'create');
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);
        UserPermissionOverride::create([
            'user_id' => $u->user_id, 'permission_id' => $p->permission_id,
            'is_granted' => false, 'granted_by' => $u->user_id, 'granted_at' => now(), 'revoked_at' => now(),
        ]);

        $this->assertTrue(app(PermissionResolver::class)->canUser($u->user_id, 'demo', 'create'));
    }

    public function test_deny_by_default_when_no_rows(): void
    {
        $u = $this->user(2);
        $this->assertFalse(app(PermissionResolver::class)->canUser($u->user_id, 'nope', 'create'));
    }
}
