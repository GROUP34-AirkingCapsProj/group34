<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the "Interbranch Transfers" nav bundle (/ist).
 *
 * The IST frontend called /ist/* endpoints that never existed in routes/api.php
 * — the real transfer flow is /transfers/*, served by TransfersPage with its
 * ReceivePanel/DispatchPanel/VerifyPanel. So the sidebar entry led to six pages
 * whose every request 404'd, duplicating a feature that already worked.
 *
 * The pages, routes and istAPI are deleted in the same change; this drops the
 * sidebar entry so nothing points at them. /ist/* now redirects to /transfers.
 *
 * down() restores the bundle and its permission link by looking the permission
 * up again, so it does not hardcode an id.
 */
return new class extends Migration
{
    private const ROUTE_PATH = '/ist';

    public function up(): void
    {
        $bundle = DB::table('nav_permission_bundles')
            ->where('route_path', self::ROUTE_PATH)
            ->first();

        if (! $bundle) {
            return;
        }

        // The pivot first — it carries an FK to the bundle.
        DB::table('nav_bundle_permissions')
            ->where('bundle_id', $bundle->bundle_id)
            ->delete();

        DB::table('nav_permission_bundles')
            ->where('bundle_id', $bundle->bundle_id)
            ->delete();
    }

    public function down(): void
    {
        if (DB::table('nav_permission_bundles')->where('route_path', self::ROUTE_PATH)->exists()) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('feature', 'inventory_transfers')
            ->where('action', 'read')
            ->value('permission_id');

        // The sequence name is explicit because this table's primary key is
        // bundle_id, not id. Laravel's PostgreSQL grammar defaults insertGetId
        // to `returning "id"`, so omitting it works on MySQL and fails on the
        // Aiven database this project deploys to.
        $bundleId = DB::table('nav_permission_bundles')->insertGetId([
            'name'            => 'Interbranch Transfers',
            'route_path'      => self::ROUTE_PATH,
            'sidebar_section' => 'INVENTORY INTELLIGENCE',
            'sidebar_icon'    => 'transfer',
            'sort_order'      => 0,
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], 'bundle_id');

        if ($permissionId) {
            DB::table('nav_bundle_permissions')->insert([
                'bundle_id'     => $bundleId,
                'permission_id' => $permissionId,
                'is_required'   => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }
};
