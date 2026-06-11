<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $roleId): User
    {
        return User::create([
            'username' => 'mw_' . uniqid(), 'first_name' => 'M', 'last_name' => 'W',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'), 'role_id' => $roleId,
        ]);
    }

    public function test_grants_pass_and_denies_403(): void
    {
        // Register a throwaway route for this test — defineRoutes() is not available in Laravel 12.
        Route::middleware(['auth:sanctum', 'permission:demo_mw,create'])
            ->get('/_test/demo-mw', fn () => response()->json(['ok' => true]));

        $p = Permission::create(['feature' => 'demo_mw', 'action' => 'create', 'description' => 'x']);
        RolePermission::create(['role_id' => 2, 'permission_id' => $p->permission_id, 'is_granted' => true]);
        RolePermission::create(['role_id' => 3, 'permission_id' => $p->permission_id, 'is_granted' => false]);

        $this->actingAs($this->user(2), 'sanctum')->getJson('/_test/demo-mw')->assertOk();
        $this->actingAs($this->user(3), 'sanctum')->getJson('/_test/demo-mw')->assertStatus(403);
    }
}
