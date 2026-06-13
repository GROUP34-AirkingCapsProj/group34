<?php

namespace Tests\Feature\Permissions;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Smoke-tests that the permission middleware is wired correctly.
 *
 * Seeds the full permission catalog + role defaults, then creates
 * short-lived users with sanctum tokens to probe real routes.
 */
class BackendWiringTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles exist before the permission seeders run.
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
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function activeStatusId(): int
    {
        // Re-use an existing row or create a minimal one so the FK passes.
        $existing = StatusLookup::first();
        if ($existing) {
            return (int) $existing->status_id;
        }

        $row = StatusLookup::forceCreate([
            'status_name'     => 'Active',
            'status_category' => 'user',
        ]);

        return (int) $row->status_id;
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('role_name', $roleName)->firstOrFail();

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => $roleName,
            'email'         => $roleName . '_' . uniqid() . '@test.local',
            'username'      => $roleName . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $this->activeStatusId(),
        ]);
    }

    // -----------------------------------------------------------------------
    // (a) warehouse_personnel POST /api/issuances → NOT 403
    //     (has inventory_issuance.create by default)
    // -----------------------------------------------------------------------
    public function test_warehouse_personnel_can_reach_issuances_store(): void
    {
        $user = $this->makeUser('warehouse_personnel');

        // We only need the middleware to pass (not a fully valid payload).
        // A 422 (validation) or 200/201 both confirm the gate was NOT 403.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/issuances', []);

        $this->assertNotEquals(403, $response->status(),
            'warehouse_personnel must not be forbidden from POST /api/issuances');
    }

    // -----------------------------------------------------------------------
    // (b) auditor POST /api/issuances → 403
    //     (auditor does NOT have inventory_issuance.create)
    // -----------------------------------------------------------------------
    public function test_auditor_is_forbidden_from_issuances_store(): void
    {
        $user = $this->makeUser('auditor');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/issuances', []);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // (c) GET /api/me/permissions for any authenticated user → 200, non-empty map
    // -----------------------------------------------------------------------
    public function test_me_permissions_returns_non_empty_map(): void
    {
        $user = $this->makeUser('warehouse_personnel');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/permissions');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertIsArray($data, 'data must be an array');
        $this->assertNotEmpty($data, 'permission map must not be empty for warehouse_personnel');

        // Spot-check: at least one key has the "feature.action" shape
        $firstKey = array_key_first($data);
        $this->assertStringContainsString('.', $firstKey,
            "Permission keys must be 'feature.action' — got: {$firstKey}");
    }
}
