<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\Permission;
use App\Models\StatusLookup;
use App\Models\User;
use App\Services\PermissionAdminService;
use App\Support\PermissionResolver;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScopeWithOverrideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_permission_override_does_not_widen_location_scope(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        $statusId = (int) (StatusLookup::where('status_category', 'user')->where('status_name', 'Active')->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id);

        $loc = Location::forceCreate([
            'location_code' => 'SCOPE_T',
            'location_name' => 'Scope Test Branch',
            'location_type' => 'branch_office',
            'status_id'     => 1,
        ]);

        // warehouse_personnel = role_id 4 (location-scoped, NOT centralized)
        $wp = User::forceCreate([
            'first_name'    => 'Scope', 'last_name' => 'WP',
            'username'      => 'scope_wp_' . uniqid(),
            'email'         => 'scope_wp_' . uniqid() . '@rbac.test',
            'password_hash' => Hash::make('x'),
            'role_id'       => 4,
            'status_id'     => $statusId,
            'location_id'   => $loc->location_id,
        ]);

        $admin = User::where('role_id', 1)->first();
        $this->assertNotNull($admin, 'an admin user must exist in the test DB');

        // Baseline: scoped, not centralized.
        $this->assertFalse($wp->isCentralized());
        $this->assertSame((int) $loc->location_id, $wp->scopedLocationId());

        // Grant the scoped user an explicit permission override.
        $perm = Permission::where('feature', 'inventory_adjustments')->where('action', 'read')->firstOrFail();
        app(PermissionAdminService::class)->setUserOverride(
            $admin, (int) $wp->user_id, (int) $perm->permission_id, true, 'guard test'
        );

        // The override is in effect...
        $eff = app(PermissionResolver::class)->effectivePermissionsFor((int) $wp->user_id);
        $this->assertTrue($eff['inventory_adjustments.read'] ?? false, 'override should be effective');

        // ...but data scope is unchanged: still scoped to their own location.
        $wp->refresh();
        $this->assertFalse($wp->isCentralized(), 'a permission override must not make a scoped user centralized');
        $this->assertSame((int) $loc->location_id, $wp->scopedLocationId(), 'a permission override must not change scoped location');
    }
}
