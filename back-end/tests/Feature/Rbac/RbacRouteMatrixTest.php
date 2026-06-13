<?php

namespace Tests\Feature\Rbac;

use App\Models\Location;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Data-driven regression contract for RBAC permission enforcement.
 *
 * Relies on the test DB (airkingV5_test) having roles, permissions, and
 * role_permissions already seeded; setUp() runs the seeders idempotently
 * via updateOrCreate so re-running is always safe.
 *
 * Uses DatabaseTransactions (NOT RefreshDatabase) — all rows created here
 * are rolled back after each test without touching the persistent seed data.
 */
class RbacRouteMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private int $activeStatusId;
    private int $locationId;

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the 5 canonical roles exist (idempotent).
        $roles = [
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ];

        foreach ($roles as $slug => $desc) {
            Role::firstOrCreate(
                ['role_name' => $slug],
                ['description' => $desc]
            );
        }

        // Re-seed permissions + role grants (updateOrCreate = idempotent).
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        // Resolve (or create) an Active user status.
        $status = StatusLookup::where('status_name', 'Active')
            ->where('status_category', 'user')
            ->first();

        if (! $status) {
            $status = StatusLookup::forceCreate([
                'status_name'     => 'Active',
                'status_category' => 'user',
            ]);
        }

        $this->activeStatusId = (int) $status->status_id;

        // Reuse first location (always present in the test DB clone) or create one.
        $loc = Location::first();
        if (! $loc) {
            $loc = Location::forceCreate(['location_name' => 'Test Branch']);
        }

        $this->locationId = (int) $loc->location_id;
    }

    // -----------------------------------------------------------------------
    // Helper: create a short-lived User for the given role slug
    // -----------------------------------------------------------------------

    private function userForRole(string $slug): User
    {
        $role = Role::where('role_name', $slug)->firstOrFail();

        $attrs = [
            'first_name'    => 'Test',
            'last_name'     => ucfirst($slug),
            'email'         => $slug . '_' . uniqid() . '@rbac.test',
            'username'      => $slug . '_' . uniqid(),
            'password_hash' => Hash::make('secret'),
            'role_id'       => $role->role_id,
            'status_id'     => $this->activeStatusId,
        ];

        // Scoped roles require a location_id so the location.scope middleware
        // can derive a location without falling back to null (which would
        // expose all locations and could mask real 403s in those routes).
        if (in_array($slug, ['branch_manager', 'warehouse_personnel'], true)) {
            $attrs['location_id'] = $this->locationId;
        }

        return User::forceCreate($attrs);
    }

    // -----------------------------------------------------------------------
    // The full matrix
    // -----------------------------------------------------------------------

    /**
     * Matrix: [method, uri, allowedRole, deniedRole]
     *
     * allowedRole → response MUST NOT be 403 (may be 200/201/404/422/500).
     * deniedRole  → response MUST be exactly 403.
     *
     * Roles and their permission grants come from PermissionSeeder::matrix().
     */
    public static function routeMatrixProvider(): array
    {
        return [
            // Feature: inventory_issuance.create
            'issuances store'                   => ['POST',  '/api/issuances',                       'warehouse_personnel', 'inventory_analyst'],
            // Feature: pos_sales_recording.create
            'sales store'                       => ['POST',  '/api/sales',                           'branch_manager',      'inventory_analyst'],
            // Feature: delivery_receiving.create
            'receivings store'                  => ['POST',  '/api/receivings',                      'warehouse_personnel', 'inventory_analyst'],
            // Feature: item_master_management.create
            'products store'                    => ['POST',  '/api/products',                        'inventory_analyst',   'branch_manager'],
            // Feature: category_management.create
            'categories store'                  => ['POST',  '/api/categories',                      'inventory_analyst',   'warehouse_personnel'],
            // Feature: supplier_management.create
            'suppliers store'                   => ['POST',  '/api/suppliers',                       'branch_manager',      'warehouse_personnel'],
            // Feature: purchase_order_creation.create
            'purchase-orders store'             => ['POST',  '/api/purchase-orders',                 'inventory_analyst',   'branch_manager'],
            // Feature: inventory_adjustments.create
            'adjustments store'                 => ['POST',  '/api/adjustments',                     'branch_manager',      'inventory_analyst'],
            // Feature: barcode_inventory_processing.create
            'inventory scan-barcode'            => ['POST',  '/api/inventory/scan-barcode',          'warehouse_personnel', 'inventory_analyst'],
            // Feature: user_account_management.create
            'users store'                       => ['POST',  '/api/users',                           'admin',               'branch_manager'],
            // Feature: purchase_request_approval.approve
            'purchase-requests approve'         => ['PATCH', '/api/purchase-requests/999999/approve', 'admin',              'inventory_analyst'],
            // Feature: inventory_transfers.approve
            'transfer-requests approve'         => ['PATCH', '/api/transfer-requests/999999/approve', 'inventory_analyst',  'branch_manager'],
            // Feature: exception_detection.read
            'compliance logs'                   => ['GET',   '/api/compliance/logs',                 'branch_manager',      'inventory_analyst'],
            // Feature: audit_trail_viewing.read
            'audit-trail v2'                    => ['GET',   '/api/audit-trail/v2',                  'branch_manager',      'inventory_analyst'],
            // Feature: purchase_request_creation.create
            'purchase-requests store'           => ['POST',  '/api/purchase-requests',               'inventory_analyst',   'warehouse_personnel'],
            // Feature: warranty_claims.create
            'warranty-claims init'              => ['POST',  '/api/warranty-claims/init',            'branch_manager',      'inventory_analyst'],
        ];
    }

    /**
     * @dataProvider routeMatrixProvider
     */
    public function test_permission_gate_allows_and_denies_correctly(
        string $method,
        string $uri,
        string $allowedRole,
        string $deniedRole
    ): void {
        // --- Allowed role must NOT get 403 ---
        $allowed = $this->actingAs($this->userForRole($allowedRole), 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->json($method, $uri, []);

        $this->assertNotEquals(
            403,
            $allowed->status(),
            "RBAC FAIL (allowed): {$method} {$uri} returned 403 for role '{$allowedRole}'."
            . " Expected permission gate to pass (any status except 403)."
        );

        // --- Denied role MUST get exactly 403 ---
        $denied = $this->actingAs($this->userForRole($deniedRole), 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->json($method, $uri, []);

        $this->assertEquals(
            403,
            $denied->status(),
            "RBAC FAIL (denied): {$method} {$uri} returned {$denied->status()} for role '{$deniedRole}'."
            . " Expected exactly 403."
        );
    }

    // -----------------------------------------------------------------------
    // Focused: admin passes every write endpoint in the matrix
    // -----------------------------------------------------------------------

    public function test_admin_passes_all_write_endpoints(): void
    {
        $admin = $this->userForRole('admin');

        $writeEndpoints = [
            ['POST',  '/api/issuances'],
            ['POST',  '/api/sales'],
            ['POST',  '/api/receivings'],
            ['POST',  '/api/products'],
            ['POST',  '/api/categories'],
            ['POST',  '/api/suppliers'],
            ['POST',  '/api/purchase-orders'],
            ['POST',  '/api/adjustments'],
            ['POST',  '/api/inventory/scan-barcode'],
            ['POST',  '/api/users'],
            ['PATCH', '/api/purchase-requests/999999/approve'],
            ['PATCH', '/api/transfer-requests/999999/approve'],
        ];

        foreach ($writeEndpoints as [$method, $uri]) {
            $response = $this->actingAs($admin, 'sanctum')
                ->withHeader('Accept', 'application/json')
                ->json($method, $uri, []);

            $this->assertNotEquals(
                403,
                $response->status(),
                "Admin should NOT be 403 on {$method} {$uri} — got {$response->status()}."
            );
        }
    }

    // -----------------------------------------------------------------------
    // Focused: auditor is blocked on writes but can read audit-trail
    // -----------------------------------------------------------------------

    public function test_auditor_is_denied_writes_via_readonly_middleware(): void
    {
        $auditor = $this->userForRole('auditor');

        $response = $this->actingAs($auditor, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/issuances', []);

        $this->assertEquals(
            403,
            $response->status(),
            'auditor.readonly middleware must block POST /api/issuances for auditor.'
        );
    }

    public function test_auditor_can_read_audit_trail(): void
    {
        $auditor = $this->userForRole('auditor');

        $response = $this->actingAs($auditor, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->getJson('/api/audit-trail/v2');

        $this->assertNotEquals(
            403,
            $response->status(),
            'Auditor has audit_trail_viewing.read — GET /api/audit-trail/v2 must NOT return 403.'
        );
    }

    // -----------------------------------------------------------------------
    // Focused: /me/permissions returns 200 + non-empty object for all roles
    // -----------------------------------------------------------------------

    public function test_me_permissions_returns_200_for_all_roles(): void
    {
        $slugs = ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'];

        foreach ($slugs as $slug) {
            $response = $this->actingAs($this->userForRole($slug), 'sanctum')
                ->withHeader('Accept', 'application/json')
                ->getJson('/api/me/permissions');

            $this->assertEquals(
                200,
                $response->status(),
                "GET /api/me/permissions must return 200 for role '{$slug}' — got {$response->status()}."
            );

            // Body must be a JSON object (array in PHP terms) and non-empty.
            $body = $response->json();
            $this->assertIsArray(
                $body,
                "GET /api/me/permissions must return a JSON object for role '{$slug}'."
            );
            $this->assertNotEmpty(
                $body,
                "GET /api/me/permissions must return a non-empty permissions map for role '{$slug}'."
            );
        }
    }
}
