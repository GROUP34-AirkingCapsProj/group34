<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire `incident_reports.dispose`.
 *
 * Disposing a hold was the last step of the old order; the inspection took that
 * job over and no route serves the action any more. Left in place the row still
 * renders as a live toggle in the RBAC console — an administrator granting or
 * revoking it would be changing nothing, which is worse than the permission not
 * being there at all.
 *
 * The grants and any per-user overrides go with it. A dangling override is the
 * nastier leftover of the two: PermissionResolver reads overrides ahead of role
 * grants, so an orphan row outlives the permission it points at and can be
 * re-attached to a different feature/action pair if the id is ever reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')
            ->where('feature', 'incident_reports')
            ->where('action', 'dispose')
            ->pluck('permission_id');

        if ($ids->isEmpty()) {
            return;
        }

        // Children first — permission_id is a foreign key in both tables.
        DB::table('user_permission_overrides')->whereIn('permission_id', $ids)->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('permission_id', $ids)->delete();
    }

    /**
     * Deliberately irreversible. Re-inserting the row would hand back a fresh
     * auto-increment id and no grants, which is not the state that was dropped;
     * PermissionSeeder is the source of truth for what should exist, and it no
     * longer lists `dispose`.
     */
    public function down(): void
    {
    }
};
