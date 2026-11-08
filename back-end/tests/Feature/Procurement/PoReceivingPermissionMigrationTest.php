<?php

namespace Tests\Feature\Procurement;

use Database\Seeders\NavBundleSeeder;
use Illuminate\Support\Facades\DB;

class PoReceivingPermissionMigrationTest extends ProcurementTestCase
{
    public function test_permission_migration_restores_previous_grants_and_navigation_on_rollback(): void
    {
        $this->seed(NavBundleSeeder::class);
        $roleId = DB::table('roles')->where('role_name', 'inventory_analyst')->value('role_id');
        $permissionId = DB::table('permissions')->where('feature', 'delivery_receiving')->where('action', 'create')->value('permission_id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->update(['is_granted' => false]);
        $bundleId = DB::table('nav_permission_bundles')->where('route_path', '/inventory-operation')->value('bundle_id');
        $barcodePermission = DB::table('permissions')->where('feature', 'barcode_inventory_processing')->where('action', 'create')->value('permission_id');
        DB::table('nav_bundle_permissions')->where('bundle_id', $bundleId)->update(['permission_id' => $barcodePermission]);
        $navBefore = DB::table('nav_bundle_permissions')->where('bundle_id', $bundleId)->get()->toJson();
        $overrides = DB::table('user_permission_overrides')->get()->toJson();
        DB::table('system_settings')->where('key', 'po_step2_grant_backup')->delete();

        $migration = require database_path('migrations/2026_10_13_000006_grant_po_receiving_permissions.php');
        $migration->up();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'is_granted' => true]);
        $this->assertDatabaseHas('nav_bundle_permissions', ['bundle_id' => $bundleId, 'permission_id' => $permissionId]);
        $migration->up(); // Retry must not replace the saved pre-migration grants.
        $migration->down();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'is_granted' => false]);
        $this->assertSame($navBefore, DB::table('nav_bundle_permissions')->where('bundle_id', $bundleId)->get()->toJson());
        $this->assertSame($overrides, DB::table('user_permission_overrides')->get()->toJson());
    }
}
