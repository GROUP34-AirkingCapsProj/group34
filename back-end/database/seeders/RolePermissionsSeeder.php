<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Build slug → role_id map from DB (never hardcode ids)
        $roleMap = Role::pluck('role_id', 'role_name')->all();

        $allSlugs = array_keys($roleMap);

        DB::transaction(function () use ($roleMap, $allSlugs) {
            foreach (PermissionSeeder::matrix() as $feature => $actions) {
                foreach ($actions as $action => $grantedRoles) {
                    $permission = Permission::where('feature', $feature)
                        ->where('action', $action)
                        ->firstOrFail();

                    foreach ($allSlugs as $slug) {
                        $rid = $roleMap[$slug];
                        RolePermission::updateOrCreate(
                            ['role_id' => $rid, 'permission_id' => $permission->permission_id],
                            ['is_granted' => in_array($slug, $grantedRoles, true)]
                        );
                    }
                }
            }
        });

        $this->command->info('Role permissions seeded.');
    }
}
