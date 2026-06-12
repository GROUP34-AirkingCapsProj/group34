<?php

namespace Tests\Feature\Permissions;

use App\Models\NavPermissionBundle;
use App\Models\Permission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NavBundleModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bundle_has_permissions_with_required_pivot(): void
    {
        $perm = Permission::firstOrCreate(['feature' => 'item_master_management', 'action' => 'read'], ['description' => 'x']);
        $bundle = NavPermissionBundle::create([
            'name' => 'Item Master', 'route_path' => '/admin/items-' . uniqid(),
            'sidebar_section' => 'PROCUREMENT & PLANNING', 'sidebar_icon' => 'items', 'sort_order' => 1,
        ]);
        $bundle->permissions()->attach($perm->permission_id, ['is_required' => true]);

        $loaded = NavPermissionBundle::with('permissions')->find($bundle->bundle_id);
        $this->assertCount(1, $loaded->permissions);
        $this->assertEquals(1, (int) $loaded->permissions->first()->pivot->is_required);
    }
}
