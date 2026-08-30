<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds inventory_issuance.fifo_override — the permission that lets a picker
 * accept a FIFO violation at issuance by supplying a written reason, instead of
 * having to file an Incident Report to pick from anywhere but the oldest batch.
 *
 * The seeder only runs on a fresh database, so this grants the new permission on
 * already-deployed ones. It is granted to exactly the roles that already hold
 * inventory_issuance.update: those are the roles that can commit a pick at all,
 * and an override is a stronger form of the same act — nobody who cannot pick
 * should be able to authorise a deviation.
 *
 * Follows RolePermissionsSeeder's pattern: a role_permissions row is written for
 * every role (not just the granted ones), with is_granted true/false —
 * PermissionResolver treats "no row" and "row with is_granted=false" the same
 * way today, but writing the false rows keeps this permission's shape consistent
 * with every other one the seeder produces, and makes the permission editor show
 * an explicit off toggle rather than a blank.
 *
 * Uses DB::table rather than the Eloquent models — the codebase's convention for
 * data-writing migrations. Role in particular uses the Auditable trait, which
 * fires boot hooks on save/delete; a migration should not trigger audit logging
 * as a side effect, especially on a database where that infrastructure may not
 * yet exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // permissions has a unique key on (feature, action) — look before insert.
        $permissionId = DB::table('permissions')
            ->where('feature', 'inventory_issuance')
            ->where('action', 'fifo_override')
            ->value('permission_id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'feature'     => 'inventory_issuance',
                'action'      => 'fifo_override',
                'description' => 'Override FIFO picking order at issuance, with a written reason',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $updateId = DB::table('permissions')
            ->where('feature', 'inventory_issuance')
            ->where('action', 'update')
            ->value('permission_id');

        $grantedRoleIds = $updateId === null
            ? []
            : DB::table('role_permissions')
                ->where('permission_id', $updateId)
                ->where('is_granted', true)
                ->pluck('role_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $roles = DB::table('roles')->pluck('role_id');

        DB::transaction(function () use ($roles, $grantedRoleIds, $permissionId) {
            foreach ($roles as $roleId) {
                $isGranted = in_array((int) $roleId, $grantedRoleIds, true);

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
            ->where('feature', 'inventory_issuance')
            ->where('action', 'fifo_override')
            ->value('permission_id');

        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('permission_id', $permissionId)->delete();
        }
    }
};
