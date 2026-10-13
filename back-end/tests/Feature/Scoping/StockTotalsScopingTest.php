<?php

namespace Tests\Feature\Scoping;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Row-level scoping is not enough on its own: /api/products and /api/inventory
 * both attach a withSum() of quantity_on_hand over the product's inventories,
 * and that subquery spans every location. A read-scoped user was therefore
 * served correctly-filtered rows carrying an org-wide STOCK figure — the number
 * the Item Master column and its stat cards render.
 *
 * The aggregate must be computed over the same set of locations the caller is
 * allowed to see, which for warehouse_personnel=4 is one and for the branch
 * manager is all of them (the last test here).
 */
class StockTotalsScopingTest extends TestCase
{
    use DatabaseTransactions;

    private int $mine;
    private int $theirs;
    private int $productId;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine   = $this->makeLocation();
        $this->theirs = $this->makeLocation();

        $this->categoryId = (int) DB::table('category_lookup')->insertGetId([
            'category_name' => 'StockCat-' . uniqid(),
            'category_type' => 'product',
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'category_id');

        $this->productId = (int) DB::table('products')->insertGetId([
            'product_code'       => 'ST-' . uniqid(),
            'product_name'       => 'Stock Scope Product ' . uniqid(),
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

        // Same product stocked at both locations, with distinguishable amounts.
        $this->stock($this->mine, 4);
        $this->stock($this->theirs, 500);
    }

    private function makeLocation(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'location_code' => 'ST-' . uniqid(),
            'location_name' => 'Stock Loc ' . uniqid(),
            'location_type' => 'warehouse',
            'is_active'     => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], 'location_id');
    }

    private function stock(int $locationId, int $qty): void
    {
        DB::table('inventory')->insert([
            'location_id'        => $locationId,
            'product_id'         => $this->productId,
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

    private function productRow(User $as): array
    {
        $rows = $this->actingAs($as)
            ->getJson('/api/products?search=' . urlencode('Stock Scope Product'))
            ->assertStatus(200)
            ->json('data');

        $row = collect($rows)->firstWhere('product_id', $this->productId);
        $this->assertNotNull($row, 'Expected the test product in /api/products.');

        return $row;
    }

    public function test_item_master_stock_total_covers_only_the_users_location(): void
    {
        $wp = $this->userAt(4, $this->mine);

        $this->assertSame(
            4,
            (int) $this->productRow($wp)['quantity'],
            'Item Master STOCK showed stock held at other locations.'
        );
    }

    public function test_centralized_user_still_sees_the_org_wide_stock_total(): void
    {
        $admin = $this->userAt(1, $this->mine);

        $this->assertSame(
            504,
            (int) $this->productRow($admin)['quantity'],
            'A centralized role must keep the org-wide total.'
        );
    }

    public function test_inventory_rows_carry_a_location_scoped_stock_total(): void
    {
        $wp = $this->userAt(4, $this->mine);

        $rows = $this->actingAs($wp)
            ->getJson('/api/inventory?per_page=200&location_id=' . $this->mine)
            ->assertStatus(200)
            ->json('data');

        $row = collect($rows)->firstWhere('product_id', $this->productId);
        $this->assertNotNull($row, 'Expected the test product in /api/inventory.');

        $this->assertSame(
            4,
            (int) ($row['product']['stock_on_hand_total'] ?? -1),
            '/api/inventory exposed an org-wide stock total to a scoped user.'
        );
    }

    public function test_centralized_user_keeps_org_wide_total_on_inventory_rows(): void
    {
        $admin = $this->userAt(1, $this->mine);

        $rows = $this->actingAs($admin)
            ->getJson('/api/inventory?per_page=200&location_id=' . $this->mine)
            ->assertStatus(200)
            ->json('data');

        $row = collect($rows)->firstWhere('product_id', $this->productId);
        $this->assertNotNull($row);

        $this->assertSame(504, (int) ($row['product']['stock_on_hand_total'] ?? -1));
    }

    public function test_branch_manager_sees_the_org_wide_stock_total(): void
    {
        $bm = $this->userAt(3, $this->mine);

        $this->assertSame(
            504,
            (int) $this->productRow($bm)['quantity'],
            'The branch manager reads every location, so Item Master STOCK is the org-wide figure.'
        );
    }

    public function test_branch_manager_keeps_org_wide_total_on_inventory_rows(): void
    {
        $bm = $this->userAt(3, $this->mine);

        $rows = $this->actingAs($bm)
            ->getJson('/api/inventory?per_page=200&location_id=' . $this->mine)
            ->assertStatus(200)
            ->json('data');

        $row = collect($rows)->firstWhere('product_id', $this->productId);
        $this->assertNotNull($row, 'Expected the test product in /api/inventory.');

        $this->assertSame(504, (int) ($row['product']['stock_on_hand_total'] ?? -1));
    }
}
