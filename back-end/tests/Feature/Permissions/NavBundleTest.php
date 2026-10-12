<?php

namespace Tests\Feature\Permissions;

use App\Models\NavBundlePermission;
use App\Models\NavPermissionBundle;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\NavBundleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NavBundleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the 5 canonical roles exist (idempotent via firstOrCreate)
        $roles = [
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ];

        foreach ($roles as $name => $desc) {
            Role::firstOrCreate(
                ['role_name' => $name],
                ['description' => $desc]
            );
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
        $this->seed(NavBundleSeeder::class);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function activeStatusId(): int
    {
        $existing = StatusLookup::first();
        if ($existing) {
            return (int) $existing->status_id;
        }

        return (int) StatusLookup::forceCreate([
            'status_name'     => 'Active',
            'status_category' => 'user',
        ])->status_id;
    }

    private function makeAdmin(): User
    {
        $role = Role::where('role_name', 'admin')->firstOrFail();

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => 'admin_' . uniqid() . '@test.local',
            'username'      => 'admin_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $this->activeStatusId(),
        ]);
    }

    // -----------------------------------------------------------------------
    // (a) 15 active bundles exist
    // -----------------------------------------------------------------------

    public function test_active_bundles_exist(): void
    {
        // Every route the seeder defines must exist as an active bundle. Asserting a
        // subset (not an exact total) keeps this robust to the static test-DB dump
        // carrying extra legacy bundles.
        $seededRoutes = array_map(fn ($row) => $row[1], NavBundleSeeder::bundles());
        $activeRoutes = NavPermissionBundle::where('is_active', true)->pluck('route_path')->all();

        $missing = array_values(array_diff($seededRoutes, $activeRoutes));

        $this->assertSame([], $missing,
            'Seeded nav bundles missing/inactive in DB: ' . implode(', ', $missing));
    }

    // -----------------------------------------------------------------------
    // (b) Each bundle has exactly 1 required permission
    // -----------------------------------------------------------------------

    public function test_each_bundle_has_exactly_one_required_permission(): void
    {
        $bundles = NavPermissionBundle::where('is_active', true)->get();

        foreach ($bundles as $bundle) {
            $requiredCount = NavBundlePermission::where('bundle_id', $bundle->bundle_id)
                ->where('is_required', true)
                ->count();

            $this->assertEquals(
                1,
                $requiredCount,
                "Bundle '{$bundle->name}' (route {$bundle->route_path}) should have exactly 1 required permission, found {$requiredCount}"
            );
        }
    }

    // -----------------------------------------------------------------------
    // (c) GET /api/nav-bundles returns 200 with 15 bundles + permissions array
    // -----------------------------------------------------------------------

    public function test_nav_bundles_endpoint_returns_200_with_all_bundles(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/nav-bundles');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertIsArray($data, 'Response data must be an array');

        // The endpoint must expose (at least) every seeded bundle route.
        $seededRoutes = array_map(fn ($row) => $row[1], NavBundleSeeder::bundles());
        $returnedRoutes = array_column($data, 'route_path');
        $missing = array_values(array_diff($seededRoutes, $returnedRoutes));

        $this->assertSame([], $missing,
            'Nav-bundle endpoint missing seeded routes: ' . implode(', ', $missing));
    }

    public function test_each_bundle_in_response_has_permissions_array(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/nav-bundles');

        $response->assertStatus(200);

        $data = $response->json('data');

        foreach ($data as $bundle) {
            $this->assertArrayHasKey('permissions', $bundle,
                "Bundle '{$bundle['name']}' is missing permissions key");

            $this->assertIsArray($bundle['permissions'],
                "Bundle '{$bundle['name']}' permissions must be an array");

            $this->assertNotEmpty($bundle['permissions'],
                "Bundle '{$bundle['name']}' must have at least one permission");

            // Verify shape of each permission entry
            foreach ($bundle['permissions'] as $perm) {
                $this->assertArrayHasKey('permission_id', $perm);
                $this->assertArrayHasKey('feature', $perm);
                $this->assertArrayHasKey('action', $perm);
                $this->assertArrayHasKey('is_required', $perm);
                $this->assertIsBool($perm['is_required'],
                    "is_required must be a boolean for bundle '{$bundle['name']}'");
            }
        }
    }

    public function test_each_bundle_in_response_has_exactly_one_required_permission(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/nav-bundles');

        $response->assertStatus(200);

        $data = $response->json('data');

        foreach ($data as $bundle) {
            $requiredPerms = array_filter(
                $bundle['permissions'],
                fn($p) => $p['is_required'] === true
            );

            $this->assertCount(
                1,
                $requiredPerms,
                "Bundle '{$bundle['name']}' must have exactly 1 required permission in API response"
            );
        }
    }

    public function test_no_active_bundle_retains_the_admin_prefix(): void
    {
        $prefixed = NavPermissionBundle::where('is_active', true)
            ->where('route_path', 'like', '/admin/%')
            ->pluck('route_path')
            ->all();

        $this->assertSame(
            [],
            $prefixed,
            'These nav bundles still carry the /admin prefix, so RoleRoute will '
            . 'not match them against the router paths: ' . implode(', ', $prefixed)
        );
    }

    public function test_every_active_bundle_route_path_is_absolute(): void
    {
        // Guards against a double-strip turning '/admin/items' into 'items'.
        $relative = NavPermissionBundle::where('is_active', true)
            ->where('route_path', 'not like', '/%')
            ->pluck('route_path')
            ->all();

        $this->assertSame([], $relative, 'Bundle route paths must start with "/".');
    }
}
