<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\UserPermissionOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_models_persist_and_relate(): void
    {
        $perm = Permission::create(['feature' => 'demo_feature', 'action' => 'create', 'description' => 'x']);
        RolePermission::create(['role_id' => 1, 'permission_id' => $perm->permission_id, 'is_granted' => true]);

        $user = User::create([
            'username' => 'm_' . uniqid(), 'first_name' => 'M', 'last_name' => 'T',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => 1,
        ]);

        $override = UserPermissionOverride::create([
            'user_id' => $user->user_id, 'permission_id' => $perm->permission_id,
            'is_granted' => false, 'granted_by' => $user->user_id, 'granted_at' => now(),
        ]);

        $this->assertEquals('demo_feature', $override->permission->feature);
        $this->assertEquals(1, RolePermission::where('permission_id', $perm->permission_id)->count());
    }
}
