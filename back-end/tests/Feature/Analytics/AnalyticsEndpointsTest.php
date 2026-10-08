<?php

namespace Tests\Feature\Analytics;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AnalyticsEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'auditor'             => 'Auditor',
            'warehouse_personnel' => 'Warehouse Personnel',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);
    }

    private function makeUser(string $roleSlug): User
    {
        $role     = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id');

        return User::forceCreate([
            'first_name'    => 'Test',
            'last_name'     => ucfirst($roleSlug),
            'email'         => $roleSlug . '_' . uniqid() . '@test.local',
            'username'      => $roleSlug . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
        ]);
    }

    public static function endpointProvider(): array
    {
        return [
            'stock level'          => ['/api/analytics/stock-level'],
            'movement'             => ['/api/analytics/movement'],
            'predictions'          => ['/api/analytics/predictions'],
            'profit loss'          => ['/api/analytics/profit-loss'],
            'location performance' => ['/api/analytics/location-performance'],
            'filters'              => ['/api/analytics/filters'],
        ];
    }

    /** @dataProvider endpointProvider */
    public function test_admin_can_read_every_endpoint(string $uri): void
    {
        $response = $this->actingAs($this->makeUser('admin'), 'sanctum')->getJson($uri);

        $response->assertOk()->assertJsonStructure(['success', 'data']);
    }

    /** @dataProvider endpointProvider */
    public function test_user_without_permission_is_forbidden(string $uri): void
    {
        // warehouse_personnel genuinely lacks analytics_dashboard.read per
        // PermissionSeeder::matrix() (only admin, inventory_analyst,
        // branch_manager, auditor are granted read on that feature).
        $response = $this->actingAs($this->makeUser('warehouse_personnel'), 'sanctum')->getJson($uri);

        $this->assertContains(
            $response->status(),
            [403, 401],
            "{$uri} must reject a user lacking analytics_dashboard.read"
        );
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/analytics/stock-level')->assertUnauthorized();
    }

    public function test_stock_level_returns_the_shape_the_ui_binds_to(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/analytics/stock-level')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'statsData'         => ['totalStockValue', 'totalUnits', 'lowStockItems', 'criticalItems'],
                    'stockDistribution' => ['optimal', 'lowStock', 'critical', 'overstock'],
                    'reorderCompliance' => ['rate', 'belowReorderPoint', 'aboveSafetyStock'],
                    'criticalItems',
                    'overstockItems',
                ],
            ]);
    }

    public function test_location_filter_is_accepted(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/analytics/stock-level?location_id=1&category_id=1')
            ->assertOk();
    }
}
