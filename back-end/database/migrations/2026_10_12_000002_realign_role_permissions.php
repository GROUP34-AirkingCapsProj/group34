<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Realigns role grants with each role's actual responsibility. The seeder is the
 * authoritative matrix but only runs on a fresh database, so this applies the
 * same change to already-deployed ones.
 *
 * What moved, and why:
 *
 *  - The branch manager approves purchase requests. That is the "Manager
 *    approves?" decision in the procurement flowchart, and it was seeded to
 *    admin only, so the approver in the documented workflow could not approve.
 *    purchase_request_creation.read goes with it: the index, show and history
 *    endpoints are gated on that action, so without it the approver cannot open
 *    the request they are being asked to decide on.
 *  - Incident inspection and resolution move from the inventory analyst to the
 *    branch manager: the analyst's remit is item master, purchasing and
 *    monitoring, and neither examining goods nor approving a write-off is that.
 *  - Transfer approval leaves the analyst for the same reason, and stays with
 *    admin.
 *  - warranty_claims.approve leaves warehouse_personnel, who file the claims —
 *    maker and approver must not be the same role.
 *  - warranty_records.update leaves the analyst and the auditor. It is
 *    PUT /warranties/{id}/claim, which is branch work; the auditor is read-only
 *    and AuditorReadOnly blocks the write regardless, so the grant was dead.
 *  - Recording a sale and maintaining the customer registry leave
 *    warehouse_personnel. They keep picking, scanning, issuance and the delivery
 *    receipt — and read on both features, which the receipt needs.
 *  - Supplier master data and item/supplier mapping leave the branch manager:
 *    purchasing is the analyst's. The branch manager keeps supplier read so they
 *    can see who a PO is going to when approving the request behind it.
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
        ['purchase_request_approval', 'approve', ['admin', 'branch_manager']],
        ['purchase_request_creation', 'read',    ['admin', 'inventory_analyst', 'branch_manager']],
        ['incident_reports',          'inspect', ['admin', 'branch_manager']],
        ['incident_reports',          'resolve', ['admin', 'branch_manager']],
        ['inventory_transfers',       'approve', ['admin']],
        ['warranty_claims',           'approve', ['admin', 'branch_manager']],
        ['warranty_records',          'update',  ['admin', 'branch_manager', 'warehouse_personnel']],
        ['sales_recording',           'create',  ['admin', 'branch_manager']],
        ['sales_recording',           'update',  ['admin', 'branch_manager']],
        ['customer_management',       'create',  ['admin', 'branch_manager']],
        ['customer_management',       'update',  ['admin', 'branch_manager']],
        ['supplier_management',       'create',  ['admin', 'inventory_analyst']],
        ['supplier_management',       'update',  ['admin', 'inventory_analyst']],
        ['item_supplier_mapping',     'create',  ['admin', 'inventory_analyst']],
        ['item_supplier_mapping',     'update',  ['admin', 'inventory_analyst']],
    ];

    /** The same pairs as they were seeded before this migration, for down(). */
    private const BEFORE = [
        ['purchase_request_approval', 'approve', ['admin']],
        ['purchase_request_creation', 'read',    ['admin', 'inventory_analyst']],
        ['incident_reports',          'inspect', ['admin', 'inventory_analyst', 'branch_manager']],
        ['incident_reports',          'resolve', ['admin', 'inventory_analyst']],
        ['inventory_transfers',       'approve', ['admin', 'inventory_analyst']],
        ['warranty_claims',           'approve', ['admin', 'branch_manager', 'warehouse_personnel']],
        ['warranty_records',          'update',  ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor']],
        ['sales_recording',           'create',  ['admin', 'branch_manager', 'warehouse_personnel']],
        ['sales_recording',           'update',  ['admin', 'branch_manager', 'warehouse_personnel']],
        ['customer_management',       'create',  ['admin', 'branch_manager', 'warehouse_personnel']],
        ['customer_management',       'update',  ['admin', 'branch_manager', 'warehouse_personnel']],
        ['supplier_management',       'create',  ['admin', 'inventory_analyst', 'branch_manager']],
        ['supplier_management',       'update',  ['admin', 'inventory_analyst', 'branch_manager']],
        ['item_supplier_mapping',     'create',  ['admin', 'inventory_analyst', 'branch_manager']],
        ['item_supplier_mapping',     'update',  ['admin', 'inventory_analyst', 'branch_manager']],
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
