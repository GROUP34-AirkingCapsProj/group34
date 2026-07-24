<?php

namespace Tests\Feature\Sets;

use App\Models\ProductSet;

class ProductSetCrudTest extends SetTestCase
{
    public function test_admin_creates_a_set_with_lines(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable(price: 100);
        $b     = $this->makeConsumable(price: 25);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/product-sets', [
            'set_code'        => 'BOQ-3TR-NONINV',
            'set_name'        => 'Std Installation Materials — 1st 10ft Piping',
            'capacity_rating' => '3TR/4HP',
            'variant'         => 'non-inverter',
            'lines'           => [
                ['product_id' => $a->product_id, 'quantity' => 2, 'spec_note' => '3/8 x 1/2 thck', 'sort_order' => 1],
                ['product_id' => $b->product_id, 'quantity' => 4, 'sort_order' => 2],
            ],
        ]);

        $res->assertCreated();
        $this->assertSame(2, count($res->json('data.lines')));
        $this->assertEqualsWithDelta(300.0, (float) $res->json('data.set_price'), 0.001);
    }

    public function test_warehouse_personnel_cannot_create_a_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        $this->actingAs($user, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'X-' . uniqid(), 'set_name' => 'Nope', 'lines' => []])
             ->assertForbidden();
    }

    public function test_warehouse_personnel_can_list_sets(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Listable']);

        $this->actingAs($user, 'sanctum')->getJson('/api/product-sets')->assertOk();
    }

    public function test_update_replaces_the_line_list_wholesale(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable();
        $c     = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Editable']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1]);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/product-sets/{$set->set_id}", [
            'set_name' => 'Edited',
            'lines'    => [['product_id' => $c->product_id, 'quantity' => 9, 'sort_order' => 1]],
        ]);

        $res->assertOk();
        $lines = $res->json('data.lines');
        $this->assertCount(1, $lines);
        $this->assertSame($c->product_id, (int) $lines[0]['product_id']);
        $this->assertEqualsWithDelta(9.0, (float) $lines[0]['quantity'], 0.001);
    }

    public function test_duplicate_set_code_is_rejected(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        ProductSet::create(['set_code' => 'DUPE-CODE', 'set_name' => 'First']);

        $this->actingAs($admin, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'DUPE-CODE', 'set_name' => 'Second', 'lines' => []])
             ->assertStatus(422);
    }

    /**
     * The set_code index is a plain unique across all rows, archived included,
     * and MySQL cannot express a partial index. So reuse must fail validation
     * with a clean 422 rather than passing validation and blowing up as a 500
     * on insert.
     */
    public function test_reusing_an_archived_set_code_is_rejected_cleanly(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['set_code' => 'ARCHIVED-CODE', 'set_name' => 'Retired']);
        $set->delete();
        $this->assertSoftDeleted('product_sets', ['set_id' => $set->set_id]);

        $this->actingAs($admin, 'sanctum')
             ->postJson('/api/product-sets', ['set_code' => 'ARCHIVED-CODE', 'set_name' => 'Reused', 'lines' => []])
             ->assertStatus(422)
             ->assertJsonValidationErrors('set_code');
    }

    /** brand_id feeds set matching, so an admin must be able to clear a wrong one. */
    public function test_nullable_fields_can_be_cleared_on_update(): void
    {
        $loc     = $this->makeLocation();
        $admin   = $this->makeUserAt($loc, 'admin');
        $brandId = (int) \Illuminate\Support\Facades\DB::table('brand_lookup')->min('brand_id');

        $set = ProductSet::create([
            'set_code'    => 'SET-' . uniqid(),
            'set_name'    => 'Has a brand',
            'brand_id'    => $brandId,
            'description' => 'original text',
        ]);

        $res = $this->actingAs($admin, 'sanctum')->putJson("/api/product-sets/{$set->set_id}", [
            'brand_id'    => null,
            'description' => null,
        ]);

        $res->assertOk();
        $this->assertNull($res->json('data.brand_id'));
        $this->assertNull($res->json('data.description'));
        $this->assertNull($set->fresh()->brand_id);
    }

    public function test_omitted_fields_are_left_untouched_on_update(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create([
            'set_code'        => 'SET-' . uniqid(),
            'set_name'        => 'Keeps its capacity',
            'capacity_rating' => '3TR/4HP',
        ]);

        $this->actingAs($admin, 'sanctum')
             ->putJson("/api/product-sets/{$set->set_id}", ['set_name' => 'Renamed only'])
             ->assertOk();

        $fresh = $set->fresh();
        $this->assertSame('Renamed only', $fresh->set_name);
        $this->assertSame('3TR/4HP', $fresh->capacity_rating);
    }

    public function test_archiving_a_set_hides_it_from_the_default_listing(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Doomed']);

        $this->actingAs($admin, 'sanctum')
             ->deleteJson("/api/product-sets/{$set->set_id}")
             ->assertOk();

        $this->assertSoftDeleted('product_sets', ['set_id' => $set->set_id]);

        $names = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets')->json('data'), 'set_name');

        $this->assertNotContains('Doomed', $names);
    }

    public function test_warehouse_personnel_cannot_update_or_archive_a_set(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');
        $set  = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Protected']);

        $this->actingAs($user, 'sanctum')
             ->putJson("/api/product-sets/{$set->set_id}", ['set_name' => 'Hacked'])
             ->assertForbidden();

        $this->actingAs($user, 'sanctum')
             ->deleteJson("/api/product-sets/{$set->set_id}")
             ->assertForbidden();

        $this->assertSame('Protected', $set->fresh()->set_name);
    }

    public function test_show_returns_the_set_with_its_lines_in_sort_order(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');
        $a     = $this->makeConsumable();
        $b     = $this->makeConsumable();

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Ordered']);
        $set->lines()->create(['product_id' => $a->product_id, 'quantity' => 1, 'sort_order' => 2]);
        $set->lines()->create(['product_id' => $b->product_id, 'quantity' => 1, 'sort_order' => 1]);

        $lines = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/product-sets/{$set->set_id}")
            ->assertOk()
            ->json('data.lines');

        $this->assertSame([1, 2], array_column($lines, 'sort_order'));
        $this->assertSame((int) $b->product_id, (int) $lines[0]['product_id']);
    }

    public function test_search_filters_the_listing_by_name_or_code(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $needle = 'Findable' . uniqid();
        ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => $needle]);
        ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Something else']);

        $names = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets?search=' . urlencode($needle))->json('data'), 'set_name');

        $this->assertSame([$needle], $names);
    }

    public function test_archived_listing_returns_soft_deleted_sets_only(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $live     = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Still Live']);
        $archived = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Retired One']);
        $archived->delete();

        $names = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets/archived')->assertOk()->json('data'), 'set_name');

        $this->assertContains('Retired One', $names);
        $this->assertNotContains('Still Live', $names);
    }

    public function test_restore_brings_a_set_back_into_the_default_listing(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Revivable']);
        $set->delete();

        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertOk();

        $this->assertNull($set->fresh()->deleted_at);

        $names = array_column($this->actingAs($admin, 'sanctum')
            ->getJson('/api/product-sets')->json('data'), 'set_name');
        $this->assertContains('Revivable', $names);
    }

    /**
     * product_sets.set_code carries a plain (non-partial) unique index — see the
     * 2026_07_24_000001 migration — so the database itself refuses a second row
     * with an archived set's code before any application code runs; the earlier
     * test_reusing_an_archived_set_code_is_rejected_cleanly proves that path.
     * That means restore()'s own "is this code already live elsewhere" guard can
     * never actually observe a conflict under the current schema — there is no
     * way to construct one without the insert itself throwing a DB integrity
     * error first. The guard stays in the controller as cheap, correct defense
     * (e.g. if the index is ever loosened to a deleted_at-scoped unique), but it
     * has no reachable "conflict found" branch to exercise here. What *is*
     * testable, and what actually matters operationally, is that restoring twice
     * is safe and that a restored set's code is usable normally again.
     */
    public function test_restoring_an_already_live_set_returns_not_found(): void
    {
        $loc   = $this->makeLocation();
        $admin = $this->makeUserAt($loc, 'admin');

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Restore Twice']);
        $set->delete();

        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertOk();

        // restore() scopes to onlyTrashed(), so calling it again on an already-live
        // set — same behaviour as LocationController::restore — is a clean 404,
        // not a 500 or a silent no-op.
        $this->actingAs($admin, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertNotFound();

        $this->assertNull($set->fresh()->deleted_at);
    }

    public function test_non_admin_gets_403_on_archived_listing_and_restore(): void
    {
        $loc  = $this->makeLocation();
        $user = $this->makeUserAt($loc, 'warehouse_personnel');

        $set = ProductSet::create(['set_code' => 'SET-' . uniqid(), 'set_name' => 'Guarded']);
        $set->delete();

        $this->actingAs($user, 'sanctum')
             ->getJson('/api/product-sets/archived')
             ->assertForbidden();

        $this->actingAs($user, 'sanctum')
             ->postJson("/api/product-sets/{$set->set_id}/restore")
             ->assertForbidden();
    }
}
