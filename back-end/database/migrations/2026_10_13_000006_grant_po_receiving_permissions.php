<?php

use App\Services\IncomingStockService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (DB::table('system_settings')->where('key', 'po_step2_grant_backup')->exists()) {
                return; // A retry must retain the original rollback evidence.
            }
            $before = [];
            foreach ([
                ['inventory_analyst', 'delivery_receiving', 'read'],
                ['inventory_analyst', 'delivery_receiving', 'create'],
                ['inventory_analyst', 'delivery_receiving', 'update'],
                ['branch_manager', 'purchase_order_creation', 'update'],
            ] as [$role, $feature, $action]) {
                $roleId = DB::table('roles')->where('role_name', $role)->value('role_id');
                $permissionId = DB::table('permissions')->where('feature', $feature)->where('action', $action)->value('permission_id');
                if ($roleId && $permissionId) {
                    $before[] = ['role_id' => $roleId, 'permission_id' => $permissionId,
                        'previous' => DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->first()];
                    DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['is_granted' => true, 'updated_at' => now()]);
                }
            }
            $bundle = DB::table('nav_permission_bundles')->where('route_path', '/inventory-operation')->first();
            $navBefore = $bundle ? DB::table('nav_bundle_permissions')->where('bundle_id', $bundle->bundle_id)->get()->all() : [];
            $receiving = DB::table('permissions')->where('feature', 'delivery_receiving')->where('action', 'create')->value('permission_id');
            if ($bundle && $receiving) {
                DB::table('nav_bundle_permissions')->where('bundle_id', $bundle->bundle_id)->delete();
                DB::table('nav_bundle_permissions')->insert(['bundle_id' => $bundle->bundle_id, 'permission_id' => $receiving, 'is_required' => true]);
            }
            DB::table('system_settings')->updateOrInsert(['key' => 'po_step2_grant_backup'], [
                'value' => json_encode(['grants' => $before, 'bundle_id' => $bundle?->bundle_id, 'nav' => $navBefore], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
        IncomingStockService::markDirty();
    }

    public function down(): void
    {
        $backup = DB::table('system_settings')->where('key', 'po_step2_grant_backup')->value('value');
        if ($backup === null) {
            return;
        }
        $before = json_decode($backup, true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($before) {
            foreach ($before['grants'] as $grant) {
                $query = DB::table('role_permissions')->where('role_id', $grant['role_id'])->where('permission_id', $grant['permission_id']);
                if ($grant['previous'] === null) {
                    $query->delete();
                } else {
                    $query->update(['is_granted' => $grant['previous']['is_granted'], 'updated_at' => $grant['previous']['updated_at']]);
                }
            }
            if ($before['bundle_id'] !== null) {
                DB::table('nav_bundle_permissions')->where('bundle_id', $before['bundle_id'])->delete();
                foreach ($before['nav'] as $row) {
                    DB::table('nav_bundle_permissions')->insert($row);
                }
            }
            DB::table('system_settings')->where('key', 'po_step2_grant_backup')->delete();
        });
    }
};
