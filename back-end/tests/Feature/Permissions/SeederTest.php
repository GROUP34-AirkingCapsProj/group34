<?php

namespace Tests\Feature\Permissions;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the 5 roles needed by RolePermissionsSeeder (rolled back after each test)
        $roles = [
            ['role_name' => 'admin',               'description' => 'System Administrator'],
            ['role_name' => 'inventory_analyst',   'description' => 'Inventory Analyst'],
            ['role_name' => 'branch_manager',      'description' => 'Branch Manager'],
            ['role_name' => 'warehouse_personnel', 'description' => 'Warehouse Personnel'],
            ['role_name' => 'auditor',             'description' => 'Auditor'],
        ];

        foreach ($roles as $r) {
            Role::firstOrCreate(['role_name' => $r['role_name']], ['description' => $r['description']]);
        }

        // Run both seeders
        Artisan::call('db:seed', ['--class' => PermissionSeeder::class]);
        Artisan::call('db:seed', ['--class' => RolePermissionsSeeder::class]);
    }

    /** (a) Every matrix feature+action must exist in the permissions table */
    public function test_all_matrix_permissions_exist(): void
    {
        $matrix = PermissionSeeder::matrix();

        foreach ($matrix as $feature => $actions) {
            foreach ($actions as $action => $_) {
                $exists = Permission::where('feature', $feature)
                    ->where('action', $action)
                    ->exists();

                $this->assertTrue($exists, "Missing permission: {$feature}.{$action}");
            }
        }
    }

    /**
     * (b) Admin must be granted ALL permissions, EXCEPT the two verification
     * "verify" actions (budget_verification.verify, purchase_order_verification.verify)
     * which are deliberately carved out to auditor-only by role default — admin
     * can only gain them via a per-user permission override. See
     * VerificationPermissionTest::test_admin_cannot_verify_budget_by_default.
     */
    public function test_admin_is_granted_all_permissions(): void
    {
        $adminId = Role::where('role_name', 'admin')->value('role_id');
        $this->assertNotNull($adminId, 'admin role not found');

        $carvedOut = Permission::where('action', 'verify')
            ->whereIn('feature', ['budget_verification', 'purchase_order_verification'])
            ->count();

        $totalPermissions = Permission::count() - $carvedOut;
        $adminGranted     = RolePermission::where('role_id', $adminId)
            ->where('is_granted', true)
            ->count();

        $this->assertEquals(
            $totalPermissions,
            $adminGranted,
            "Admin should have all permissions granted except the {$carvedOut} auditor-only verify carve-outs, got {$adminGranted}"
        );
    }

    /** (c) Spot-checks for warehouse_personnel and auditor */
    public function test_spot_checks(): void
    {
        $wpId      = Role::where('role_name', 'warehouse_personnel')->value('role_id');
        $auditorId = Role::where('role_name', 'auditor')->value('role_id');

        $this->assertNotNull($wpId,      'warehouse_personnel role not found');
        $this->assertNotNull($auditorId, 'auditor role not found');

        // warehouse_personnel: inventory_issuance.create → GRANTED
        $issuanceCreate = Permission::where('feature', 'inventory_issuance')
            ->where('action', 'create')
            ->firstOrFail();

        $wpIssuanceCreate = RolePermission::where('role_id', $wpId)
            ->where('permission_id', $issuanceCreate->permission_id)
            ->firstOrFail();

        $this->assertTrue(
            (bool) $wpIssuanceCreate->is_granted,
            'warehouse_personnel should have inventory_issuance.create granted'
        );

        // warehouse_personnel: item_master_management.create → NOT GRANTED
        $itemCreate = Permission::where('feature', 'item_master_management')
            ->where('action', 'create')
            ->firstOrFail();

        $wpItemCreate = RolePermission::where('role_id', $wpId)
            ->where('permission_id', $itemCreate->permission_id)
            ->firstOrFail();

        $this->assertFalse(
            (bool) $wpItemCreate->is_granted,
            'warehouse_personnel should NOT have item_master_management.create granted'
        );

        // auditor: item_master_management.read → GRANTED
        $itemRead = Permission::where('feature', 'item_master_management')
            ->where('action', 'read')
            ->firstOrFail();

        $auditorItemRead = RolePermission::where('role_id', $auditorId)
            ->where('permission_id', $itemRead->permission_id)
            ->firstOrFail();

        $this->assertTrue(
            (bool) $auditorItemRead->is_granted,
            'auditor should have item_master_management.read granted'
        );

        // auditor: inventory_issuance.create → NOT GRANTED
        $auditorIssuanceCreate = RolePermission::where('role_id', $auditorId)
            ->where('permission_id', $issuanceCreate->permission_id)
            ->firstOrFail();

        $this->assertFalse(
            (bool) $auditorIssuanceCreate->is_granted,
            'auditor should NOT have inventory_issuance.create granted'
        );
    }
}
