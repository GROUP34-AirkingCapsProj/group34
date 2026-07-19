<?php

namespace Tests\Feature\Restock;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RestockEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin'               => 'System Administrator',
            'inventory_analyst'   => 'Inventory Analyst',
            'branch_manager'      => 'Branch Manager',
            'warehouse_personnel' => 'Warehouse Personnel',
            'auditor'             => 'Auditor',
        ] as $name => $desc) {
            Role::firstOrCreate(['role_name' => $name], ['description' => $desc]);
        }
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        $whId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Test Warehouse',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');

    }

    private function makeUser(string $roleSlug): User
    {
        $role = Role::where('role_name', $roleSlug)->firstOrFail();
        $statusId = StatusLookup::query()->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id;

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

    private function makeProductWithDemand(): int
    {
        $pid = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Endpoint Test Product',
            'product_type'       => 'installation_material',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 5,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => (int) DB::table('locations')->where('location_type', 'warehouse')->value('location_id'),
            'product_id'         => $pid,
            'quantity_on_hand'   => 1,
            'available_quantity' => 1,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $issuanceId = DB::table('issuances')->insertGetId([
            'issuance_date' => now()->toDateString(),
            'issuance_type' => 'Operations',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'issuance_id');
        DB::table('issuance_details')->insert([
            'issuance_id'        => $issuanceId,
            'product_id'         => $pid,
            'quantity_issued'    => 10,
            'quantity_in_pieces' => 10,
            'unit_price'         => 100,
            'subtotal'           => 1000,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return $pid;
    }

    public function test_auditor_can_read_recommendations(): void
    {
        $resp = $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->getJson('/api/restock/recommendations');
        $resp->assertOk()->assertJsonStructure(['data' => ['run_at', 'recommendations']]);
    }

    public function test_auditor_cannot_recalculate(): void
    {
        $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->postJson('/api/restock/recalculate')
            ->assertForbidden();
    }

    public function test_warehouse_personnel_cannot_read(): void
    {
        $this->actingAs($this->makeUser('warehouse_personnel'), 'sanctum')
            ->getJson('/api/restock/recommendations')
            ->assertForbidden();
    }

    public function test_inventory_analyst_recalculate_persists_and_returns_rows(): void
    {
        $pid = $this->makeProductWithDemand();

        $resp = $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson('/api/restock/recalculate');

        $resp->assertOk();
        $this->assertDatabaseHas('restock_recommendations', ['product_id' => $pid]);
        $ids = array_map(
            fn ($r) => (int) $r['product_id'],
            $resp->json('data.recommendations')
        );
        $this->assertContains($pid, $ids);
    }

    public function test_recommendations_can_be_filtered_by_location(): void
    {
        $pid = $this->makeProductWithDemand(); // stock 1 at first warehouse -> low
        $ia  = $this->makeUser('inventory_analyst');

        // Populate the batch.
        $this->actingAs($ia, 'sanctum')->postJson('/api/restock/recalculate')->assertOk();

        $whId = (int) DB::table('locations')->where('location_type', 'warehouse')
            ->orderBy('location_id')->value('location_id');

        $resp = $this->actingAs($ia, 'sanctum')
            ->getJson('/api/restock/recommendations?location_id=' . $whId);

        $resp->assertOk();
        foreach ($resp->json('data.recommendations') as $row) {
            $this->assertSame($whId, (int) $row['location_id']);
        }
        $ids = array_map(fn ($r) => (int) $r['product_id'], $resp->json('data.recommendations'));
        $this->assertContains($pid, $ids);
    }
}
