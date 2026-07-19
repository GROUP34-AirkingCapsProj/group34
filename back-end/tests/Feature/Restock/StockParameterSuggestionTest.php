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

class StockParameterSuggestionTest extends TestCase
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

    /** @return array{0:int,1:int} [product_id, suggestion_id] */
    private function makeSuggestion(): array
    {
        $pid = (int) DB::table('products')->insertGetId([
            'product_code'       => 'TST-' . uniqid(),
            'product_name'       => 'Suggestion Test Product',
            'product_type'       => 'installation_material',
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 5,
            'safety_stock'       => 2,
            'max_stock_level'    => 10,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        $sid = (int) DB::table('stock_parameter_suggestions')->insertGetId([
            'product_id'            => $pid,
            'current_recommended'   => 5,
            'suggested_recommended' => 19,
            'current_safety'        => 2,
            'suggested_safety'      => 5,
            'current_max'           => 10,
            'suggested_max'         => 65,
            'reason_codes'          => json_encode(['FAST_MOVING']),
            'status'                => 'pending',
            'created_at'            => now(),
            'updated_at'            => now(),
        ], 'suggestion_id');

        return [$pid, $sid];
    }

    public function test_list_pending_suggestions(): void
    {
        [, $sid] = $this->makeSuggestion();
        $resp = $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->getJson('/api/restock/suggestions');
        $resp->assertOk();
        $ids = array_map(fn ($s) => (int) $s['suggestion_id'], $resp->json('data'));
        $this->assertContains($sid, $ids);
    }

    public function test_suggestions_include_warehouses_the_product_is_stocked_at(): void
    {
        [$pid, $sid] = $this->makeSuggestion();

        $whId = (int) DB::table('locations')->insertGetId([
            'location_code' => 'TST-WH-' . uniqid(),
            'location_name' => 'Main WH',
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
        DB::table('inventory')->insert([
            'location_id'        => $whId,
            'product_id'         => $pid,
            'quantity_on_hand'   => 10,
            'available_quantity' => 10,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $resp = $this->actingAs($this->makeUser('auditor'), 'sanctum')
            ->getJson('/api/restock/suggestions');
        $resp->assertOk();

        $row = collect($resp->json('data'))->firstWhere('suggestion_id', $sid);
        $this->assertNotNull($row);
        $this->assertIsArray($row['warehouses']);
        $this->assertContains('Main WH', array_column($row['warehouses'], 'location_name'));
        $this->assertContains($whId, array_column($row['warehouses'], 'location_id'));
    }

    public function test_apply_updates_product_and_marks_applied(): void
    {
        [$pid, $sid] = $this->makeSuggestion();
        $ia = $this->makeUser('inventory_analyst');

        $this->actingAs($ia, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")
            ->assertOk();

        $this->assertDatabaseHas('products', [
            'product_id'         => $pid,
            'recommended_stocks' => 19,
            'safety_stock'       => 5,
            'max_stock_level'    => 65,
        ]);
        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'applied',
            'reviewed_by'   => $ia->user_id,
        ]);
    }

    public function test_apply_rejects_stale_suggestion_when_product_changed(): void
    {
        [$pid, $sid] = $this->makeSuggestion();
        DB::table('products')->where('product_id', $pid)->update(['recommended_stocks' => 99]);

        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")
            ->assertStatus(409);

        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'pending',
        ]);
    }

    public function test_apply_rejects_non_pending_suggestion(): void
    {
        [, $sid] = $this->makeSuggestion();
        DB::table('stock_parameter_suggestions')->where('suggestion_id', $sid)
            ->update(['status' => 'dismissed']);

        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")
            ->assertStatus(422);
    }

    public function test_dismiss_marks_dismissed(): void
    {
        [, $sid] = $this->makeSuggestion();
        $ia = $this->makeUser('inventory_analyst');

        $this->actingAs($ia, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/dismiss")
            ->assertOk();

        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'dismissed',
            'reviewed_by'   => $ia->user_id,
        ]);
    }

    public function test_dismiss_rejects_non_pending_suggestion(): void
    {
        [, $sid] = $this->makeSuggestion();
        DB::table('stock_parameter_suggestions')->where('suggestion_id', $sid)
            ->update(['status' => 'applied']);

        $this->actingAs($this->makeUser('inventory_analyst'), 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/dismiss")
            ->assertStatus(422);

        $this->assertDatabaseHas('stock_parameter_suggestions', [
            'suggestion_id' => $sid,
            'status'        => 'applied',
        ]);
    }

    public function test_auditor_cannot_apply_or_dismiss(): void
    {
        [, $sid] = $this->makeSuggestion();
        $auditor = $this->makeUser('auditor');

        $this->actingAs($auditor, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/apply")->assertForbidden();
        $this->actingAs($auditor, 'sanctum')
            ->postJson("/api/restock/suggestions/{$sid}/dismiss")->assertForbidden();
    }
}
