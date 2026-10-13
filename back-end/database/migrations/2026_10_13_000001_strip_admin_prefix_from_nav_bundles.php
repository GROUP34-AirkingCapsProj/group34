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
 *
 * The rewrite happens in PHP, one row at a time, rather than in SQL. The obvious
 * `CONCAT('/', SUBSTRING(route_path, 8))` is not safe across both engines this
 * project runs: development is MySQL, production is PostgreSQL on Aiven, and
 * PostgreSQL overloads `substring(text, text)` as a REGEX function. If the
 * planner resolves the integer argument to that overload it treats "8" as a
 * pattern and returns NULL, which would null out every route_path and leave the
 * sidebar with nothing to match. The table holds about thirty rows, so a loop
 * costs nothing and removes the dialect question entirely.
 */
return new class extends Migration
{
    private const PREFIX = '/admin/';

    public function up(): void
    {
        $rows = DB::table('nav_permission_bundles')
            ->where('route_path', 'like', self::PREFIX . '%')
            ->get(['bundle_id', 'route_path']);

        foreach ($rows as $row) {
            DB::table('nav_permission_bundles')
                ->where('bundle_id', $row->bundle_id)
                ->update([
                    // strlen('/admin/') === 7, and the leading slash is put back.
                    'route_path' => '/' . substr($row->route_path, 7),
                ]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('nav_permission_bundles')
            ->where('route_path', 'like', '/%')
            ->where('route_path', 'not like', self::PREFIX . '%')
            ->get(['bundle_id', 'route_path']);

        foreach ($rows as $row) {
            DB::table('nav_permission_bundles')
                ->where('bundle_id', $row->bundle_id)
                ->update(['route_path' => '/admin' . $row->route_path]);
        }
    }
};
