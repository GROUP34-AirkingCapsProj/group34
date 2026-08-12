<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;
use Illuminate\Support\Facades\DB;

class ProductSetCrudTest extends SetTestCase
{
    /** @return int[] */
    private function brandIds(int $n = 2): array
    {
        return DB::table('brand_lookup')->limit($n)->pluck('brand_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_admin_creates_a_set_with_lines(): void
    {
        $loc      = $this->makeLocation();
        $admin    = $this->makeUserAt($loc, 'admin');
        $a        = $this->makeConsumable(price: 100);
        $b        = $this->makeConsumable(price: 25);
        $brandIds = $this->brandIds(2);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/product-sets', [
            'brand_ids'       => $brandIds,
            'capacity_rating' => '3TR/4HP',
            'variant'         => 'non-inverter',
            'lines'           => [
                ['product_id' => $a->product_id, 'quantity' => 2, 'spec_note' => '3/8 x 1/2 thck', 'sort_order' => 1],
                ['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2],
            ],
        ]);

        $res->assertCreated();

        // Brands round-trip.
        $returnedBrandIds = array_column($res->json('data.brands'), 'brand_id');
        sort($returnedBrandIds);
        $expected = $brandIds;
        sort($expected);
        $this->assertSame($expected, array_map('intval', $returnedBrandIds));

        // Derived label is non-empty and reflects the posted capacity.
        $this->assertNotEmpty($res->json('data.label'));
        $this->assertStringContainsString('3TR/4HP', $res->json('data.label'));

        // Lines round-trip with integer piece quantities and a computed price.
        $this->assertSame(2, count($res->json('data.lines')));
        $this->assertSame(2, (int) $res->json('data.lines.0.quantity'));
        $this->assertIsInt($res->json('data.lines.0.quantity'));
        $this->assertEqualsWithDelta(300.0, (float) $res->json('data.set_price'), 0.001);
    }

    public function test_packaged_material_set_price_counts_the_package_price_only_once(): void
    {
        $loc = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $product = $this->makeConsumable(price: 500, cost: 500);
        $product->consumableDetail()->create([
            'pieces_per_package' => 50,
            'package_unit' => 'box',
        ]);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/product-sets', [
            'brand_ids' => $this->brandIds(1),
            'lines' => [[
                'product_id' => $product->product_id,
                'quantity' => 50,
            ]],
        ]);

        $res->assertCreated();
        $this->assertEqualsWithDelta(500.0, (float) $res->json('data.set_price'), 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $res->json('data.lines.0.line_total'), 0.001);
    }

    public function test_warehouse_personnel_cannot_create_a_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')
             ->postJson('/api/product-sets', ['brand_ids' => $this->brandIds(1), 'capacity_rating' => '1TR', 'lines' => []])
             ->assertForbidden();
    }

    public function test_warehouse_personnel_can_list_sets(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        ProductSet::create(['capacity_rating' => '2TR', 'variant' => 'inverter']);

        $this->actingAs($user, 'sanctum')->getJson('/api/product-sets')->assertOk();
    }

    public function test_update_replaces_the_line_list_wholesale(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable();
        $c     = $this->makeConsumable();

        $set = ProductSet::create(['capacity_rating' => '2TR']);
        $set->brands()->sync($this->brandIds(1));
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/product-sets/{$set->set_id}", [
            'capacity_rating' => '3TR',
            'lines'           => [['product_id' => $c->product_id, 'quantity' => 9, 'sort_order' => 1]],
        ]);

        $res->assertOk();
        $lines = $res->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertSame($c->product_id, (int) $lines[0]['product_id']);
        $this->assertSame(9, (int) $lines[0]['quantity']);
    }

    public function test_update_replaces_the_brand_list_wholesale(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $all = $this->brandIds(2);
        $this->assertGreaterThanOrEqual(2, count($all), 'need at least 2 brands in brand_lookup');

        $set = ProductSet::create(['capacity_rating' => '2TR']);
        $set->brands()->sync([$all[0]]);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/product-sets/{$set->set_id}", [
            'brand_ids' => [$all[1]],
        ]);

        $res->assertOk();
        $returned = array_map('intval', array_column($res->json('data.brands'), 'brand_id'));
        $this->assertSame([$all[1]], $returned);
        $this->assertSame([$all[1]], $set->brands()->pluck('brand_lookup.brand_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_omitted_fields_are_left_untouched_on_update(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['capacity_rating' => '3TR/4HP', 'variant' => 'non-inverter']);
        $set->brands()->sync($this->brandIds(1));

        $this->actingAs($admin, 'sanctum')
             ->putJson("/api/product-sets/{$set->set_id}", ['variant' => 'inverter'])
             ->assertOk();

        $fresh = $set->fresh();
        $this->assertSame('inverter', $fresh->variant);
        $this->assertSame('3TR/4HP', $fresh->capacity_rating);
        // brand_ids omitted → brands left untouched.
        $this->assertCount(1, $fresh->brands);
    }

    public function test_archiving_a_set_hides_it_from_the_default_listing(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['capacity_rating' => 'DOOMED-CAP']);

        $this->actingAs($admin, 'sanctum')
             ->deleteJson("/api/product-sets/{$set->set_id}")
             ->assertOk();

        $this->assertSoftDeleted('product_sets', ['set_id' => $set->set_id]);

        $caps = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets')->json('data'), 'capacity_rating');

        $this->assertNotContains('DOOMED-CAP', $caps);
    }

    public function test_warehouse_personnel_cannot_update_or_archive_a_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $set  = ProductSet::create(['capacity_rating' => '2TR']);

        $this->actingAs($user, 'sanctum')
             ->putJson("/api/product-sets/{$set->set_id}", ['capacity_rating' => '9TR'])
             ->assertForbidden();

        $this->actingAs($user, 'sanctum')
             ->deleteJson("/api/product-sets/{$set->set_id}")
             ->assertForbidden();

        $this->assertSame('2TR', $set->fresh()->capacity_rating);
    }

    public function test_show_returns_the_set_with_its_lines_in_sort_order(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable();
        $b     = $this->makeConsumable();

        $set = ProductSet::create(['capacity_rating' => '2TR']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1, 'sort_order' => 2]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 1, 'sort_order' => 1]);

        $lines = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/product-sets/{$set->set_id}")
            ->assertOk()
            ->json('data.lines');

        $this->assertSame([1, 2], array_column($lines, 'sort_order'));
        $this->assertSame((int) $b->product_id, (int) $lines[0]['product_id']);
    }

    public function test_search_filters_the_listing_by_capacity(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $needle = 'FIND' . strtoupper(uniqid());
        ProductSet::create(['capacity_rating' => $needle]);
        ProductSet::create(['capacity_rating' => 'OTHER-CAP']);

        $caps = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets?search=' . urlencode($needle))->json('data'), 'capacity_rating');

        $this->assertSame([$needle], $caps);
    }

    public function test_archived_listing_returns_soft_deleted_sets_only(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $live     = ProductSet::create(['capacity_rating' => 'LIVE-CAP']);
        $archived = ProductSet::create(['capacity_rating' => 'RETIRED-CAP']);
        $archived->delete();

        $caps = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets/archived')->assertOk()->json('data'), 'capacity_rating');

        $this->assertContains('RETIRED-CAP', $caps);
        $this->assertNotContains('LIVE-CAP', $caps);
    }

    public function test_restore_brings_a_set_back_into_the_default_listing(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['capacity_rating' => 'REVIVE-CAP']);
        $set->delete();

        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertOk();

        $this->assertNull($set->fresh()->deleted_at);

        $caps = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets')->json('data'), 'capacity_rating');
        $this->assertContains('REVIVE-CAP', $caps);
    }

    public function test_restoring_an_already_live_set_returns_not_found(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['capacity_rating' => '2TR']);
        $set->delete();

        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertOk();

        // restore() scopes to onlyTrashed(), so calling it again on an already-live
        // set is a clean 404, not a 500 or a silent no-op.
        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertNotFound();

        $this->assertNull($set->fresh()->deleted_at);
    }

    public function test_non_admin_gets_403_on_archived_listing_and_restore(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        $set = ProductSet::create(['capacity_rating' => '2TR']);
        $set->delete();

        $this->actingAs($user, 'sanctum')
             ->getJson('/api/product-sets/archived')
             ->assertForbidden();

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertForbidden();
    }
}
