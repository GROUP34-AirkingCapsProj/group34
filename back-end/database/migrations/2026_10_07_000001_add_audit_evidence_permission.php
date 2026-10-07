<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds audit_evidence.read / audit_evidence.resolve_exception and the
 * "Audit & Evidence" nav bundle to already-deployed databases.
 *
 * These shipped in PermissionSeeder and NavBundleSeeder only (commit 8acc4b6),
 * and the seeders run on a fresh database -- so production has the audit-evidence
 * read API from cce5cce with no permission row behind it, and every request to it
 * is denied for everybody, including admins. This migration is the missing half.
 *
 * Why not just run the seeders against production: RolePermissionsSeeder rewrites
 * is_granted for every role x every permission in the matrix, and production has
 * at least one deliberate divergence from it -- permission_audit_logs row 402
 * records an admin revoking product_set_management.read from inventory_analyst on
 * 2026-09-09. Running the seeder would silently restore it. A migration touches
 * only these two permissions and leaves every other grant alone.
 *
 * Grants mirror PermissionSeeder::matrix():
 *   read              => admin, auditor
 *   resolve_exception => auditor
 *
 * Follows 2026_08_30_000006_add_fifo_override_permission: DB::table rather than
 * the Eloquent models (Role uses the Auditable trait, and a migration should not
 * emit audit-log rows as a side effect), and a role_permissions row written for
 * every role -- granted or not -- so the permission editor shows an explicit off
 * toggle rather than a blank.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> action => roles that hold it */
    private const GRANTS = [
        'read'              => ['admin', 'auditor'],
        'resolve_exception' => ['auditor'],
    ];

    private const FEATURE = 'audit_evidence';

    private const BUNDLE = [
        'name'            => 'Audit & Evidence',
        'route_path'      => '/admin/audit-evidence',
        'sidebar_section' => 'COMPLIANCE & SUPPORT',
        'sidebar_icon'    => 'audit',
        'requires'        => 'read',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $roleIds = DB::table('roles')->pluck('role_id', 'role_name');
            $permissionIds = [];

            foreach (self::GRANTS as $action => $grantedRoles) {
                $permissionId = $this->ensurePermission($action);
                $permissionIds[$action] = $permissionId;

                $granted = $roleIds->only($grantedRoles)->map(fn ($id) => (int) $id)->values()->all();

                foreach ($roleIds as $roleId) {
                    $this->setGrant((int) $roleId, $permissionId, in_array((int) $roleId, $granted, true));
                }
            }

            $this->ensureBundle($permissionIds[self::BUNDLE['requires']]);
        });
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('feature', self::FEATURE)
            ->pluck('permission_id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($permissionIds) {
            $bundleId = DB::table('nav_permission_bundles')
                ->where('route_path', self::BUNDLE['route_path'])
                ->value('bundle_id');

            if ($bundleId !== null) {
                DB::table('nav_bundle_permissions')->where('bundle_id', $bundleId)->delete();
                DB::table('nav_permission_bundles')->where('bundle_id', $bundleId)->delete();
            }

            DB::table('user_permission_overrides')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        });
    }

    /**
     * permissions has a unique key on (feature, action) -- look before insert, so
     * a database that already has the row (one seeded fresh after 8acc4b6) is
     * left alone rather than failing the whole migration.
     */
    private function ensurePermission(string $action): int
    {
        $existing = DB::table('permissions')
            ->where('feature', self::FEATURE)
            ->where('action', $action)
            ->value('permission_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        // Same wording PermissionSeeder generates, so a fresh database and a
        // migrated one do not drift apart on the description column.
        $description = ucfirst($action) . ' access for ' . str_replace('_', ' ', self::FEATURE);

        return (int) DB::table('permissions')->insertGetId([
            'feature'     => self::FEATURE,
            'action'      => $action,
            'description' => $description,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], 'permission_id');
    }

    private function setGrant(int $roleId, int $permissionId, bool $isGranted): void
    {
        $exists = DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();

        if ($exists) {
            // Only reached when this migration is re-run or the seeder got there
            // first; both should converge on the matrix.
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->update(['is_granted' => $isGranted, 'updated_at' => now()]);

            return;
        }

        DB::table('role_permissions')->insert([
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'is_granted'    => $isGranted,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function ensureBundle(int $permissionId): void
    {
        $bundleId = DB::table('nav_permission_bundles')
            ->where('route_path', self::BUNDLE['route_path'])
            ->value('bundle_id');

        if ($bundleId === null) {
            // NavBundleSeeder numbers sort_order 0-based per section from its own
            // list. Appending after the current maximum puts this last in
            // COMPLIANCE & SUPPORT, which is where the seeder's list has it, without
            // renumbering bundles an admin may have reordered.
            $sortOrder = (int) DB::table('nav_permission_bundles')
                ->where('sidebar_section', self::BUNDLE['sidebar_section'])
                ->max('sort_order');

            $bundleId = (int) DB::table('nav_permission_bundles')->insertGetId([
                'name'            => self::BUNDLE['name'],
                'route_path'      => self::BUNDLE['route_path'],
                'sidebar_section' => self::BUNDLE['sidebar_section'],
                'sidebar_icon'    => self::BUNDLE['sidebar_icon'],
                'sort_order'      => $sortOrder + 1,
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ], 'bundle_id');
        }

        $linked = DB::table('nav_bundle_permissions')
            ->where('bundle_id', $bundleId)
            ->where('permission_id', $permissionId)
            ->exists();

        if (! $linked) {
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
