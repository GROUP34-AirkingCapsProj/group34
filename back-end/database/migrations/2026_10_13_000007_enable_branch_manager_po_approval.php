<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('role_name', 'branch_manager')->value('role_id');
        $permissionId = DB::table('permissions')->where('feature', 'purchase_order_creation')
            ->where('action', 'update')->value('permission_id');
        if ($roleId && $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['is_granted' => true, 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Preserve the existing seeded grant; rolling back UI changes must not
        // revoke the branch manager's established purchase approval responsibility.
    }
};
