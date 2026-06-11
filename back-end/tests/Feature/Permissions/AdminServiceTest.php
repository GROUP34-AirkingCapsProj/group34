<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\PermissionAdminService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'username' => 'a_' . uniqid(), 'first_name' => 'A', 'last_name' => 'D',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 1,
        ]);
    }

    public function test_set_role_default_writes_value_and_audit(): void
    {
        $admin = $this->admin();
        $p = Permission::create(['feature' => 'svc', 'action' => 'create', 'description' => 'x']);
        RolePermission::create(['role_id' => 3, 'permission_id' => $p->permission_id, 'is_granted' => false]);

        app(PermissionAdminService::class)->setRoleDefault($admin, 3, $p->permission_id, true);

        $this->assertTrue(RolePermission::where('role_id', 3)->where('permission_id', $p->permission_id)->first()->is_granted);
        $log = PermissionAuditLog::where('change_type', 'role_default')->where('permission_id', $p->permission_id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(3, $log->target_role_id);
        $this->assertFalse($log->old_value);
        $this->assertTrue($log->new_value);
    }

    public function test_set_user_override_revokes_prior_active_and_logs(): void
    {
        $admin = $this->admin();
        $target = User::create([
            'username' => 't_' . uniqid(), 'first_name' => 'T', 'last_name' => 'U',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 3,
        ]);
        $p = Permission::create(['feature' => 'svc', 'action' => 'update', 'description' => 'x']);

        $svc = app(PermissionAdminService::class);
        $svc->setUserOverride($admin, $target->user_id, $p->permission_id, true, 'first');
        $svc->setUserOverride($admin, $target->user_id, $p->permission_id, false, 'second');

        $active = UserPermissionOverride::where('user_id', $target->user_id)
            ->where('permission_id', $p->permission_id)->whereNull('revoked_at')->get();
        $this->assertCount(1, $active);
        $this->assertFalse((bool) $active->first()->is_granted);
        $this->assertEquals(2, PermissionAuditLog::where('change_type', 'user_override')->where('permission_id', $p->permission_id)->count());
    }

    public function test_clear_user_override_revokes_and_logs_back_to_default(): void
    {
        $admin = $this->admin();
        $target = User::create([
            'username' => 'c_' . uniqid(), 'first_name' => 'C', 'last_name' => 'U',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 3,
        ]);
        $p = Permission::create(['feature' => 'svc', 'action' => 'approve', 'description' => 'x']);
        RolePermission::create(['role_id' => 3, 'permission_id' => $p->permission_id, 'is_granted' => true]);

        $svc = app(PermissionAdminService::class);
        $svc->setUserOverride($admin, $target->user_id, $p->permission_id, false, 'deny it');
        $svc->clearUserOverride($admin, $target->user_id, $p->permission_id, 'reset');

        $this->assertEquals(0, UserPermissionOverride::where('user_id', $target->user_id)
            ->where('permission_id', $p->permission_id)->whereNull('revoked_at')->count());
        $this->assertEquals(1, \App\Models\PermissionAuditLog::where('change_type', 'user_override_clear')
            ->where('permission_id', $p->permission_id)->count());
    }

    public function test_set_role_default_creates_row_when_absent(): void
    {
        $admin = $this->admin();
        $p = \App\Models\Permission::create(['feature' => 'svc', 'action' => 'read', 'description' => 'x']);

        app(\App\Services\PermissionAdminService::class)->setRoleDefault($admin, 4, $p->permission_id, true);

        $rp = \App\Models\RolePermission::where('role_id', 4)->where('permission_id', $p->permission_id)->first();
        $this->assertNotNull($rp);
        $this->assertTrue($rp->is_granted);
        $log = \App\Models\PermissionAuditLog::where('change_type', 'role_default')
            ->where('permission_id', $p->permission_id)->where('target_role_id', 4)->first();
        $this->assertNotNull($log);
        $this->assertNull($log->old_value); // no prior row → old is null
        $this->assertTrue($log->new_value);
    }
}
