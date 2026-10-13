<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $roleId = DB::table('roles')->where('role_name', 'branch_manager')->value('role_id');
            if (!$roleId) {
                return;
            }
            $userIds = DB::table('users')->where('role_id', $roleId)->pluck('user_id');
            foreach ([
                ['purchase_request_creation', 'read'],
                ['purchase_request_approval', 'approve'],
                ['purchase_order_creation', 'read'],
                ['purchase_order_creation', 'update'],
            ] as [$feature, $action]) {
                $permissionId = DB::table('permissions')->where('feature', $feature)
                    ->where('action', $action)->value('permission_id');
                if (!$permissionId) {
                    continue;
                }
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['is_granted' => true, 'updated_at' => now()]
                );
                // Apply the requested branch-manager purchase approval policy
                // to existing accounts too; legacy denies outrank role grants.
                DB::table('user_permission_overrides')->whereIn('user_id', $userIds)
                    ->where('permission_id', $permissionId)->where('is_granted', false)
                    ->update(['is_granted' => true, 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // These grants restore the seeded responsibility. A rollback must not
        // silently revoke purchase approval access or recreate legacy denies.
    }
};
