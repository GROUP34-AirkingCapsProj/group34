<?php

namespace Tests\Feature\Scoping;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Product;
use App\Models\BudgetAllocation;
use App\Models\RestockRecommendation;

class BudgetRestockScopingTest extends TestCase
{
    use DatabaseTransactions;

    protected function loc(string $type = 'warehouse'): \App\Models\Location
    {
        return \App\Models\Location::create([
            'location_code' => 'L-' . uniqid(),
            'location_name' => 'Loc ' . uniqid(),
            'location_type' => $type,
        ]);
    }

    protected function userAt(int $roleId, ?int $locationId): \App\Models\User
    {
        return \App\Models\User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    /**
     * NOTE: GET /api/budget-allocations is gated by CheckRole to
     * admin,inventory_analyst,auditor only — all three are centralized roles
     * per User::isCentralized(). A branch_manager (role 3, scoped) can never
     * reach this endpoint at all (403 before the controller runs), so the
     * scoping behavior can't be exercised over HTTP with a scoped role today.
     * We instead assert the scope directly against the query builder, which
     * is what the controller now applies via ->forUser($request->user()).
     */
    public function test_scoped_user_query_sees_only_their_location_budgets(): void
    {
        $a = $this->loc(); $b = $this->loc();
        BudgetAllocation::create([
            'location_id'  => $a->location_id,
            'period_label' => 'P-' . uniqid(),
            'total_budget' => 100,
            'period_start' => now()->toDateString(),
            'period_end'   => now()->addMonth()->toDateString(),
        ]);
        BudgetAllocation::create([
            'location_id'  => $b->location_id,
            'period_label' => 'P-' . uniqid(),
            'total_budget' => 200,
            'period_start' => now()->toDateString(),
            'period_end'   => now()->addMonth()->toDateString(),
        ]);

        $bm = $this->userAt(3, $a->location_id);
        $rows = BudgetAllocation::forUser($bm)->get();
        $locs = $rows->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_scoped_user_sees_only_their_location_restock(): void
    {
        $a = $this->loc(); $b = $this->loc();
        foreach ([$a, $b] as $l) {
            $p = Product::create(['product_code' => 'P-' . uniqid(), 'product_name' => 'P', 'product_type' => 'installation_material']);
            RestockRecommendation::create([
                'product_id' => $p->product_id, 'location_id' => $l->location_id,
                'current_stock' => 1, 'reorder_level' => 5, 'recommended_quantity' => 10,
                'priority' => 'HIGH', 'avg_daily_demand' => 1,
            ]);
        }

        $bm = $this->userAt(3, $a->location_id);
        // /api/restock/recommendations is permission-gated (po_recommendations,read),
        // not role-gated — grant the scoped test user that permission so we
        // can exercise the scoping behavior over the real HTTP endpoint.
        // (The airkingV5_test DB predates this permission row, so seed it.)
        $permId = \Illuminate\Support\Facades\DB::table('permissions')
            ->where('feature', 'po_recommendations')->where('action', 'read')
            ->value('permission_id');
        if ($permId === null) {
            $permId = \Illuminate\Support\Facades\DB::table('permissions')->insertGetId([
                'feature' => 'po_recommendations', 'action' => 'read',
                'description' => 'Read access for po recommendations',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \Illuminate\Support\Facades\DB::table('user_permission_overrides')->insert([
            'user_id' => $bm->user_id, 'permission_id' => $permId, 'is_granted' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->actingAs($bm)->getJson('/api/restock/recommendations')->assertStatus(200)->json('data.recommendations');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertNotContains($b->location_id, $locs);
    }

    public function test_admin_sees_all_budgets(): void
    {
        $a = $this->loc(); $b = $this->loc();
        BudgetAllocation::create([
            'location_id'  => $a->location_id,
            'period_label' => 'P-' . uniqid(),
            'total_budget' => 100,
            'period_start' => now()->toDateString(),
            'period_end'   => now()->addMonth()->toDateString(),
        ]);
        BudgetAllocation::create([
            'location_id'  => $b->location_id,
            'period_label' => 'P-' . uniqid(),
            'total_budget' => 200,
            'period_start' => now()->toDateString(),
            'period_end'   => now()->addMonth()->toDateString(),
        ]);

        $admin = $this->userAt(1, null);
        $data = $this->actingAs($admin)->getJson('/api/budget-allocations')->assertStatus(200)->json('data');
        $locs = collect($data)->pluck('location_id')->unique()->all();
        $this->assertContains($a->location_id, $locs);
        $this->assertContains($b->location_id, $locs);
    }
}
