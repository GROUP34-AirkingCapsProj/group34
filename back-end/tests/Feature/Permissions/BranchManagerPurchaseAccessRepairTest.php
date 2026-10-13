<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Support\PermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchManagerPurchaseAccessRepairTest extends TestCase
{
    use DatabaseTransactions;

    public function test_migration_repairs_legacy_purchase_denials_for_branch_managers(): void
    {
        $manager = User::forceCreate([
            'first_name' => 'Approval', 'last_name' => 'Manager',
            'username' => 'approval_repair_'.uniqid(),
            'email' => uniqid().'@approval.test', 'password_hash' => bcrypt('test'),
            'role_id' => DB::table('roles')->where('role_name', 'branch_manager')->value('role_id'),
        ]);
        $permissions = [
            ['purchase_request_creation', 'read'],
            ['purchase_request_approval', 'approve'],
            ['purchase_order_creation', 'read'],
            ['purchase_order_creation', 'update'],
        ];
        foreach ($permissions as [$feature, $action]) {
            $id = DB::table('permissions')->where('feature', $feature)->where('action', $action)->value('permission_id');
            DB::table('user_permission_overrides')->updateOrInsert(
                ['user_id' => $manager->user_id, 'permission_id' => $id],
                ['is_granted' => false, 'updated_at' => now()]
            );
        }
        $migration = require database_path('migrations/2026_10_13_000008_restore_branch_manager_purchase_approval_access.php');
        $migration->up();
        $resolver = new PermissionResolver;
        foreach ($permissions as [$feature, $action]) {
            $this->assertTrue($resolver->canUser($manager->user_id, $feature, $action), "$feature.$action");
        }
    }

    public function test_purchase_order_read_dependencies_are_included_in_the_release(): void
    {
        $this->assertInstanceOf(\App\Services\PoLineBalanceService::class, app(\App\Services\PoLineBalanceService::class));
        $this->assertSame('draft', \App\Support\PoLifecycle::compute([], false));
    }
}
