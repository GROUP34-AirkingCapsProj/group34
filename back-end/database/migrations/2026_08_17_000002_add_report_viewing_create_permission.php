<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeder only runs on a fresh database. This grants report_viewing.create
 * on already-deployed ones, to the same three roles that already hold
 * report_viewing.read — generating a report writes a file and a report_runs
 * row, so it needs to be its own action rather than folded into `read`.
 *
 * Follows RolePermissionsSeeder's pattern: a role_permissions row is written
 * for every role (not just the granted ones), with is_granted true/false —
 * PermissionResolver treats "no row" and "row with is_granted=false" the same
 * way today, but writing the false rows keeps this permission's shape
 * consistent with every other one the seeder produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['feature' => 'report_viewing', 'action' => 'create'],
            ['description' => 'Create access for report viewing']
        );

        $grantedSlugs = ['admin', 'inventory_analyst', 'auditor'];

        $roleMap = Role::pluck('role_id', 'role_name')->all();

        DB::transaction(function () use ($roleMap, $grantedSlugs, $permission) {
            foreach ($roleMap as $slug => $roleId) {
                RolePermission::updateOrCreate(
                    ['role_id' => $roleId, 'permission_id' => $permission->permission_id],
                    ['is_granted' => in_array($slug, $grantedSlugs, true)]
                );
            }
        });
    }

    public function down(): void
    {
        $permission = Permission::where('feature', 'report_viewing')
            ->where('action', 'create')->first();

        if ($permission) {
            RolePermission::where('permission_id', $permission->permission_id)->delete();
            $permission->delete();
        }
    }
};
