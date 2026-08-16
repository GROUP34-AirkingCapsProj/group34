<?php

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
 *
 * Uses DB::table rather than the Eloquent models — the codebase's convention
 * for data-writing migrations (see 2026_08_12_000005_drop_incident_dispose_
 * permission.php). Role in particular uses the Auditable trait, which fires
 * boot hooks on save/delete; a migration should not trigger audit logging as
 * a side effect, especially on a database where that infrastructure may not
 * yet exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('feature', 'report_viewing')
            ->where('action', 'create')
            ->value('permission_id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'feature'     => 'report_viewing',
                'action'      => 'create',
                'description' => 'Create access for report viewing',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $grantedSlugs = ['admin', 'inventory_analyst', 'auditor'];

        $roles = DB::table('roles')->pluck('role_name', 'role_id');

        DB::transaction(function () use ($roles, $grantedSlugs, $permissionId) {
            foreach ($roles as $roleId => $slug) {
                $isGranted = in_array($slug, $grantedSlugs, true);

                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if ($exists) {
                    DB::table('role_permissions')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $permissionId)
                        ->update([
                            'is_granted' => $isGranted,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('role_permissions')->insert([
                        'role_id'       => $roleId,
                        'permission_id' => $permissionId,
                        'is_granted'    => $isGranted,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('feature', 'report_viewing')
            ->where('action', 'create')
            ->value('permission_id');

        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('permission_id', $permissionId)->delete();
        }
    }
};
