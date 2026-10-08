<?php

namespace Tests\Feature\Dashboard;

use App\Models\Location;
use App\Models\StatusLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function activeUserStatusId(): int
    {
        return (int) (StatusLookup::where('status_category', 'user')->where('status_name', 'Active')->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id);
    }

    public function test_scoped_user_location_distribution_only_contains_their_location(): void
    {
        $statusId = $this->activeUserStatusId();

        $locA = Location::forceCreate(['location_code' => 'DSH_A', 'location_name' => 'Dash A', 'location_type' => 'branch_office', 'status_id' => 1]);
        $locB = Location::forceCreate(['location_code' => 'DSH_B', 'location_name' => 'Dash B', 'location_type' => 'warehouse', 'status_id' => 1]);

        $bm = User::forceCreate([
            'first_name' => 'Dash', 'last_name' => 'BM',
            'username' => 'dash_bm_' . uniqid(), 'email' => 'dash_bm_' . uniqid() . '@t.test',
            'password_hash' => Hash::make('x'), 'role_id' => 3, 'status_id' => $statusId,
            'location_id' => $locA->location_id,
        ]);

        $res = $this->actingAs($bm)->getJson('/api/dashboard');
        $res->assertOk();

        $labels = $res->json('location_distribution.labels');
        $this->assertEquals(['Dash A'], $labels, 'scoped user must only see their own location');
        $this->actingAs($bm)->getJson('/api/dashboard/stock-movement-trends')
            ->assertOk()
            ->assertJsonPath('data.current_stock', 0)
            ->assertJsonPath('data.total_in', 0)
            ->assertJsonPath('data.total_out', 0)
            ->assertJsonPath('data.products', []);

    }

    public function test_centralized_user_sees_all_locations(): void
    {
        $admin = User::where('role_id', 1)->first();
        $this->assertNotNull($admin, 'an admin user must exist in the test DB');

        // Create two locations within this test's transaction so they exist when the
        // admin request runs; DatabaseTransactions rolls them back automatically after.
        Location::forceCreate(['location_code' => 'DSH_C1', 'location_name' => 'Dash C1', 'location_type' => 'branch_office', 'status_id' => 1]);
        Location::forceCreate(['location_code' => 'DSH_C2', 'location_name' => 'Dash C2', 'location_type' => 'warehouse',     'status_id' => 1]);

        $res = $this->actingAs($admin)->getJson('/api/dashboard');
        $res->assertOk();

        $labels = $res->json('location_distribution.labels');
        $this->assertContains('Dash C1', $labels === null ? [] : $labels, 'centralized user should see seeded/other locations');
        $this->assertGreaterThan(1, count($labels ?? []), 'centralized payload should include multiple locations');
    }
}
