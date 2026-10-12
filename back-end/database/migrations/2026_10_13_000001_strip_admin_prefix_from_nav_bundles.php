<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the /admin prefix from nav_permission_bundles.route_path.
 *
 * NavBundleController serves route_path straight to the frontend, and RoleRoute
 * matches those values against location.pathname. The router lost its /admin
 * prefix, so this column has to move with it or every sidebar page fails its
 * permission check and bounces to the dashboard.
 *
 * Prefix-guarded, so re-running is a no-op rather than a second strip.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('nav_permission_bundles')
            ->where('route_path', 'like', '/admin/%')
            ->update([
                'route_path' => DB::raw("CONCAT('/', SUBSTRING(route_path, 8))"),
            ]);
    }

    public function down(): void
    {
        DB::table('nav_permission_bundles')
            ->where('route_path', 'like', '/%')
            ->where('route_path', 'not like', '/admin/%')
            ->update([
                'route_path' => DB::raw("CONCAT('/admin', route_path)"),
            ]);
    }
};
