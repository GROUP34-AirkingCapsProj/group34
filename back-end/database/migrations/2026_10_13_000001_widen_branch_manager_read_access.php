<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens the branch manager's reach to match its role: the admin's deputy, able
 * to look at every branch's data rather than only its own.
 *
 * Two halves to that change, and only one of them lives here.
 *
 * The location half is code, not data: User::readsAllLocations() now includes
 * branch_manager=3, so every read scope keyed to it spans all locations. The
 * write boundary (User::isCentralized(), hasStrictLocation()) is deliberately
 * unchanged — a manager still receives, issues, adjusts and transfers only at
 * its own branch, and ResolvesUserLocation still pins the location on writes.
 *
 * The feature half is data, which is what this migration applies. The branch
 * manager gains read on four features it lacked:
 *
 *  - report_viewing read + create. It could not open or generate a single
 *    report before. Every report narrows its rows through
 *    ReportDefinition::build($params, $user), which keys off readsAllLocations(),
 *    so the output spans branches by the same rule its screens now do.
 *  - audit_evidence.read. It already reads the audit trail and the exception
 *    list; this is the evidence view those two point at. resolve_exception stays
 *    the auditor's alone.
 *  - category_management.read and lookup_management.read — the item reference
 *    catalogues the analyst maintains. Read only: create, update and archive on
 *    both stay where they were.
 *
 * Administration is untouched and stays admin-only: user_account_management,
 * role_assignment, permission_audit, system_configuration,
 * location_based_inventory and data_archiving are not in the list below, and no
 * row for them is written.
 *
 * Per-user overrides in user_permission_overrides are deliberately untouched —
 * they are an admin's explicit decision about one person and outrank the role
 * default by design.
 *
 * Uses DB::table rather than the Eloquent models, per the convention for
 * data-writing migrations in this codebase: Role and Permission use the
 * Auditable trait, and a migration should not emit audit rows as a side effect.
 */
return new class extends Migration
{
    /** [feature, action, role slugs granted after this migration] */
    private const AFTER = [
        ['report_viewing',      'read',   ['admin', 'inventory_analyst', 'branch_manager', 'auditor']],
        ['report_viewing',      'create', ['admin', 'inventory_analyst', 'branch_manager', 'auditor']],
        ['audit_evidence',      'read',   ['admin', 'branch_manager', 'auditor']],
        ['category_management', 'read',   ['admin', 'inventory_analyst', 'branch_manager', 'auditor']],
        ['lookup_management',   'read',   ['admin', 'inventory_analyst', 'branch_manager', 'auditor']],
    ];

    /** The same pairs as they were seeded before this migration, for down(). */
    private const BEFORE = [
        ['report_viewing',      'read',   ['admin', 'inventory_analyst', 'auditor']],
        ['report_viewing',      'create', ['admin', 'inventory_analyst', 'auditor']],
        ['audit_evidence',      'read',   ['admin', 'auditor']],
        ['category_management', 'read',   ['admin', 'inventory_analyst', 'auditor']],
        ['lookup_management',   'read',   ['admin', 'inventory_analyst', 'auditor']],
    ];

    public function up(): void
    {
        $this->apply(self::AFTER);
    }

    public function down(): void
    {
        $this->apply(self::BEFORE);
    }

    private function apply(array $grants): void
    {
        $roleIds = DB::table('roles')->pluck('role_id', 'role_name')->all();

        if ($roleIds === []) {
            return; // nothing seeded yet; RolePermissionsSeeder will do the work
        }

        DB::transaction(function () use ($grants, $roleIds) {
            foreach ($grants as [$feature, $action, $granted]) {
                $permissionId = DB::table('permissions')
                    ->where('feature', $feature)
                    ->where('action', $action)
                    ->value('permission_id');

                if ($permissionId === null) {
                    continue; // feature absent on this database; nothing to realign
                }

                foreach ($roleIds as $slug => $roleId) {
                    $isGranted = in_array($slug, $granted, true);

                    $row = DB::table('role_permissions')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $permissionId);

                    // Written as exists-then-update rather than updateOrInsert so an
                    // existing row keeps its original created_at.
                    if ($row->exists()) {
                        $row->update(['is_granted' => $isGranted, 'updated_at' => now()]);
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
            }
        });
    }
};
