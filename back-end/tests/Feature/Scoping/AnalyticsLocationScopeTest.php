<?php

namespace Tests\Feature\Scoping;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * analytics_dashboard.read is granted to branch_manager (role 3), which is a
 * location-scoped role. The dashboard actions take location_id straight off the
 * query string, and a null location_id means "every location" — so without
 * enforcement a branch manager can read another branch's numbers, or org-wide
 * totals, simply by changing the parameter.
 *
 * Scoped roles must be pinned to their own location the same way writes are
 * (see App\Traits\ResolvesUserLocation). Centralized roles keep the ability to
 * pass any location_id, including none at all for an org-wide view.
 */
class AnalyticsLocationScopeTest extends TestCase
{
    use DatabaseTransactions;

    private int $mine;
    private int $theirs;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine   = $this->makeLocation();
        $this->theirs = $this->makeLocation();

        $this->categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'ScopeCat-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        // Distinct, recognisable unit counts per location.
        $this->stockAt($this->mine, 7);
        $this->stockAt($this->theirs, 999);
    }

    private function makeLocation(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'SC-' . uniqid(),
            'location_name' => 'Scope Loc ' . uniqid(),
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function stockAt(int $locationId, int $qty): void
    {
        $productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'SC-' . uniqid(),
            'product_name'       => 'Scope Product',
            'product_type'       => 'appliance',
            'category_id'        => $this->categoryId,
            'unit_price'         => 100,
            'cost_price'         => 80,
            'recommended_stocks' => 10,
            'safety_stock'       => 5,
            'max_stock_level'    => 100000,
            'status_id'          => 3,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], 'product_id');

        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $productId,
            'quantity_on_hand'   => $qty,
            'available_quantity' => $qty,
            'reorder_level'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function userAt(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    private function units(array $json): int
    {
        return (int) $json['data']['statsData']['totalUnits'];
    }

    public function test_scoped_user_cannot_read_another_locations_analytics(): void
    {
        $bm = $this->userAt(3, $this->mine);

        $json = $this->actingAs($bm)
            ->getJson('/api/analytics/stock-level?location_id=' . $this->theirs . '&category_id=' . $this->categoryId)
            ->assertStatus(200)
            ->json();

        $this->assertSame(
            7,
            $this->units($json),
            'A scoped user asking for another location must still be served their own location.'
        );
    }

    public function test_scoped_user_without_a_location_param_does_not_get_org_wide_totals(): void
    {
        $bm = $this->userAt(3, $this->mine);

        $json = $this->actingAs($bm)
            ->getJson('/api/analytics/stock-level?category_id=' . $this->categoryId)
            ->assertStatus(200)
            ->json();

        $this->assertSame(
            7,
            $this->units($json),
            'Omitting location_id must not widen a scoped user to every location.'
        );
    }

    public function test_centralized_user_may_still_read_any_location(): void
    {
        $analyst = $this->userAt(2, $this->mine);

        $json = $this->actingAs($analyst)
            ->getJson('/api/analytics/stock-level?location_id=' . $this->theirs . '&category_id=' . $this->categoryId)
            ->assertStatus(200)
            ->json();

        $this->assertSame(999, $this->units($json));
    }

    public function test_scoped_user_sees_only_their_own_branch_in_location_performance(): void
    {
        $bm = $this->userAt(3, $this->mine);

        $rows = $this->actingAs($bm)
            ->getJson('/api/analytics/location-performance?category_id=' . $this->categoryId)
            ->assertStatus(200)
            ->json('data.branchPerformanceData');

        $ids = collect($rows)->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertNotContains(
            $this->theirs,
            $ids,
            'The branch performance chart exposed another location to a scoped user.'
        );
        $this->assertSame([$this->mine], $ids);
    }

    public function test_scoped_user_only_gets_their_own_location_in_the_filter_dropdown(): void
    {
        $bm = $this->userAt(3, $this->mine);

        $locations = $this->actingAs($bm)
            ->getJson('/api/analytics/filters')
            ->assertStatus(200)
            ->json('data.locations');

        $ids = collect($locations)->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertSame([$this->mine], $ids, 'The location filter must offer only the location the user can actually see.');
    }

    public function test_centralized_user_sees_every_branch_in_location_performance(): void
    {
        $analyst = $this->userAt(2, $this->mine);

        $rows = $this->actingAs($analyst)
            ->getJson('/api/analytics/location-performance?category_id=' . $this->categoryId)
            ->assertStatus(200)
            ->json('data.branchPerformanceData');

        $ids = collect($rows)->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertContains($this->mine, $ids);
        $this->assertContains($this->theirs, $ids);
    }

    public function test_centralized_user_still_gets_org_wide_totals_when_unfiltered(): void
    {
        $admin = $this->userAt(1, $this->mine);

        $json = $this->actingAs($admin)
            ->getJson('/api/analytics/stock-level?category_id=' . $this->categoryId)
            ->assertStatus(200)
            ->json();

        $this->assertSame(1006, $this->units($json), 'A centralized role keeps the org-wide view.');
    }
}
