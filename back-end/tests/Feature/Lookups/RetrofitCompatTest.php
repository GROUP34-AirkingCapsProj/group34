<?php

namespace Tests\Feature\Lookups;

use App\Models\ApplianceDetail;
use App\Models\BrandLookup;
use App\Models\CapacityLookup;
use App\Models\CategoryLookup;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Product;
use App\Models\UnitLookup;
use Illuminate\Support\Facades\DB;

/**
 * Task 10: UnitLookupController, CategoryLookupController and
 * BrandLookupController move onto HandlesLookupCrud. The point of this file
 * is that nothing observable changed for existing consumers of these three
 * controllers — the frontend and dashboards must see identical shapes.
 */
class RetrofitCompatTest extends LookupTestCase
{
    protected function unit(): UnitLookup
    {
        return UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u',
        ]);
    }

    /**
     * Exercises the real default path: unit_abbreviation omitted, exactly
     * as the Item Lookups page's optional Abbreviation field posts when
     * left blank (ConvertEmptyStringsToNull turns "" into null upstream of
     * the controller). unit_lookup.unit_abbreviation used to be NOT NULL
     * with no default, which made this 500 regardless of the `nullable`
     * validation rule; migration 2026_07_30_000005 fixed the column to
     * match its rule.
     */
    protected function createUnit(?string $name = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/units', [
            'unit_name' => $name ?? $this->uniqueValue(),
        ]);
    }

    // -----------------------------------------------------------------
    // 1-2: units index shape + usage_count
    // -----------------------------------------------------------------

    public function test_units_index_returns_name_and_abbreviation(): void
    {
        $this->actingAsAdmin();
        $name = $this->uniqueValue();

        $this->postJson('/api/units', [
            'unit_name'         => $name,
            'unit_abbreviation' => 'xyz',
        ])->assertStatus(201);

        $row = collect($this->getJson('/api/units')->json('data'))
            ->firstWhere('unit_name', $name);

        $this->assertNotNull($row);
        $this->assertSame('xyz', $row['unit_abbreviation']);
    }

    public function test_units_carry_a_nonzero_usage_count_when_referenced_by_a_product(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        $product = \App\Models\Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Test Product ' . uniqid(),
            'product_type' => 'installation_material',
        ]);

        DB::table('installation_material_details')->insert([
            'product_id' => $product->product_id,
            'unit_id'    => $unit->unit_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = collect($this->getJson('/api/units')->json('data'))
            ->firstWhere('unit_id', $unit->unit_id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('usage_count', $row);
        $this->assertSame(1, $row['usage_count']);
    }

    // -----------------------------------------------------------------
    // unit_abbreviation is nullable both in validation and at the DB layer
    // (migration 2026_07_30_000005). The Item Lookups page's Abbreviation
    // field is optional; a blank input posts "" -> null via
    // ConvertEmptyStringsToNull, and an explicit null must behave the same.
    // -----------------------------------------------------------------

    public function test_create_unit_without_abbreviation_succeeds_with_null(): void
    {
        $this->actingAsAdmin();
        $name = $this->uniqueValue();

        $response = $this->postJson('/api/units', ['unit_name' => $name]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.unit_abbreviation'));
        $this->assertDatabaseHas('unit_lookup', [
            'unit_name'         => $name,
            'unit_abbreviation' => null,
        ]);
    }

    public function test_create_unit_with_explicit_null_abbreviation_succeeds(): void
    {
        $this->actingAsAdmin();
        $name = $this->uniqueValue();

        $response = $this->postJson('/api/units', [
            'unit_name'         => $name,
            'unit_abbreviation' => null,
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.unit_abbreviation'));
        $this->assertDatabaseHas('unit_lookup', [
            'unit_name'         => $name,
            'unit_abbreviation' => null,
        ]);
    }

    public function test_units_index_returns_abbreviation_key_when_null(): void
    {
        $this->actingAsAdmin();
        $name = $this->uniqueValue();

        $this->postJson('/api/units', ['unit_name' => $name])->assertStatus(201);

        $row = collect($this->getJson('/api/units')->json('data'))
            ->firstWhere('unit_name', $name);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('unit_abbreviation', $row);
        $this->assertNull($row['unit_abbreviation']);
    }

    // -----------------------------------------------------------------
    // unit_name (varchar(50)) and unit_abbreviation (varchar(10)) width
    // boundaries. The rules used to say max:100/max:20 — wider than the
    // columns — so an over-length value reached MySQL under strict mode and
    // raised SQLSTATE 22001 (a 500) instead of a clean 422.
    // -----------------------------------------------------------------

    public function test_unit_name_max_width_boundary(): void
    {
        $this->actingAsAdmin();

        $exactly50 = str_pad('U' . substr(uniqid(), -8), 50, 'x');
        $ok = $this->postJson('/api/units', ['unit_name' => $exactly50]);
        $ok->assertStatus(201);

        $over50 = str_pad('U' . substr(uniqid(), -8), 51, 'x');
        $tooLong = $this->postJson('/api/units', ['unit_name' => $over50]);
        $tooLong->assertStatus(422);
        $tooLong->assertJsonValidationErrors(['unit_name']);
    }

    public function test_unit_abbreviation_max_width_boundary(): void
    {
        $this->actingAsAdmin();

        $exactly10 = str_repeat('A', 10);
        $ok = $this->postJson('/api/units', [
            'unit_name'         => $this->uniqueValue(),
            'unit_abbreviation' => $exactly10,
        ]);
        $ok->assertStatus(201);

        $over10 = str_repeat('A', 11);
        $tooLong = $this->postJson('/api/units', [
            'unit_name'         => $this->uniqueValue(),
            'unit_abbreviation' => $over10,
        ]);
        $tooLong->assertStatus(422);
        $tooLong->assertJsonValidationErrors(['unit_abbreviation']);
    }

    // -----------------------------------------------------------------
    // 3: ?search= on both units lists
    // -----------------------------------------------------------------

    public function test_search_filters_units_index_and_archived(): void
    {
        $this->actingAsAdmin();
        $needle = 'Findme' . uniqid();
        $haystack = 'Other' . uniqid();

        $matchId = $this->createUnit($needle)->json('data.unit_id');
        $this->createUnit($haystack);

        $activeIds = collect($this->getJson('/api/units?search=Findme')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertContains($matchId, $activeIds);
        $this->assertCount(1, $activeIds);

        $this->deleteJson("/api/units/{$matchId}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/units/archived?search=Findme')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertContains($matchId, $archivedIds);
        $this->assertCount(1, $archivedIds);
    }

    // -----------------------------------------------------------------
    // 4: units archive -> archived -> restore round-trip
    // -----------------------------------------------------------------

    public function test_units_archive_then_list_archived_then_restore(): void
    {
        $this->actingAsAdmin();
        $id = $this->createUnit()->json('data.unit_id');

        $this->deleteJson("/api/units/{$id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/units/archived')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertContains($id, $archivedIds);

        $activeIds = collect($this->getJson('/api/units')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertNotContains($id, $activeIds);

        $this->postJson("/api/units/{$id}/restore")->assertStatus(200);

        $activeIds = collect($this->getJson('/api/units')->json('data'))
            ->pluck('unit_id')->all();
        $this->assertContains($id, $activeIds);
    }

    // -----------------------------------------------------------------
    // 5-6: categories aggregate shapes
    // -----------------------------------------------------------------

    protected function location(): Location
    {
        return Location::create([
            'location_code' => 'LOC-' . uniqid(),
            'location_name' => 'Test Location ' . uniqid(),
            'location_type' => 'warehouse',
        ]);
    }

    /**
     * Creates a product with a known unit_price and an inventory row with a
     * known quantity_on_hand, so total_units/total_value/avg_price can be
     * asserted to exact numbers rather than merely being present. No
     * installation_material_details row, so pieces_per_package defaults to
     * 1 in the controller's aggregate SQL — value = quantity * unit_price.
     */
    protected function productWithKnownStock(array $productAttrs, int $quantityOnHand): Product
    {
        $product = Product::create(array_merge([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Test Product ' . uniqid(),
            'product_type' => 'appliance',
        ], $productAttrs));

        Inventory::create([
            'location_id'        => $this->location()->location_id,
            'product_id'         => $product->product_id,
            'quantity_on_hand'   => $quantityOnHand,
            'available_quantity' => $quantityOnHand,
            'reorder_level'      => 0,
        ]);

        return $product;
    }

    /**
     * Pins the aggregate to real numbers, not just presence of the keys:
     * this SQL could return zero for everything and firstWhere() would still
     * find the row with `items_count`/`total_units`/`total_value` keys
     * intact. Two products of known unit_price and quantity_on_hand, no
     * installation_material_details row (pieces_per_package defaults to 1),
     * so the expected totals are exact: units 5+10=15, value
     * 5*100 + 10*50 = 1000, avg_price 1000/15 rounded to 2dp.
     */
    public function test_categories_index_returns_aggregate_fields(): void
    {
        $this->actingAsAdmin();
        $categoryId = $this->postJson('/api/categories', ['category_name' => $this->uniqueValue()])
            ->json('data.category_id');

        $this->productWithKnownStock(['category_id' => $categoryId, 'unit_price' => 100], 5);
        $this->productWithKnownStock(['category_id' => $categoryId, 'unit_price' => 50], 10);

        $row = collect($this->getJson('/api/categories')->json('data'))
            ->firstWhere('category_id', $categoryId);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['items_count']);
        $this->assertSame(15, $row['total_units']);
        $this->assertEquals(1000.0, $row['total_value']);
        $this->assertEquals(round(1000 / 15, 2), $row['avg_price']);
        // Item 7: category rows now also carry usage_count, uniform with
        // capacity/variant/preset/unit; for categories it equals items_count.
        $this->assertSame(2, $row['usage_count']);
    }

    public function test_categories_performance_returns_200(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/categories/performance')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // -----------------------------------------------------------------
    // 7: categories archive -> archived -> restore
    // -----------------------------------------------------------------

    public function test_categories_archive_then_list_archived_then_restore(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/categories', ['category_name' => $this->uniqueValue()])
            ->json('data.category_id');

        $this->deleteJson("/api/categories/{$id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/categories/archived')->json('data'))
            ->pluck('category_id')->all();
        $this->assertContains($id, $archivedIds);

        $this->postJson("/api/categories/{$id}/restore")->assertStatus(200);

        $activeIds = collect($this->getJson('/api/categories')->json('data'))
            ->pluck('category_id')->all();
        $this->assertContains($id, $activeIds);
    }

    /**
     * The restore-conflict scenario the reviewer traced (archive
     * "Compressors", create a new active "Compressors", Restore the
     * archived one) turns out NOT to be reachable for categories via the
     * API: category_lookup.category_name carries a real DB-level UNIQUE
     * constraint that is NOT scoped by deleted_at (confirmed via
     * information_schema and airkingv5.sql:4432 — `ADD UNIQUE KEY
     * category_lookup_category_name_unique (category_name)`), unlike
     * capacity_lookup/variant_lookup, whose scope_idx indexes are
     * non-unique and enforce uniqueness purely at the app layer. An
     * archived row's name is still occupying the unique index, so the
     * "create a new active row with the same name" step itself is
     * impossible while the row stays archived — you cannot even reach the
     * restore call with a conflict in place.
     *
     * What that mismatch actually produces is a DIFFERENT bug:
     * lookupUniqueRule()'s ->whereNull('deleted_at') tells the validator an
     * archived name is free to reuse, the DB disagrees, and the create
     * would 500 with SQLSTATE 23000 instead of a clean 422. This test pins
     * the fix (CategoryLookupController::store() now catches that
     * QueryException). See LookupCrudTraitTest for the restore-conflict
     * guard itself, proven against capacity (which has no such DB
     * constraint and can actually reach it).
     */
    public function test_categories_create_reusing_an_archived_name_returns_422_not_500(): void
    {
        $this->actingAsAdmin();
        $name = $this->uniqueValue();

        $archivedId = $this->postJson('/api/categories', ['category_name' => $name])
            ->json('data.category_id');
        $this->deleteJson("/api/categories/{$archivedId}")->assertStatus(200);

        $response = $this->postJson('/api/categories', ['category_name' => $name]);

        $response->assertStatus(422);
        $this->assertSame(
            1,
            CategoryLookup::withTrashed()->where('category_name', $name)->count(),
            'The DB must still hold exactly the one archived row — the conflicting insert must not have landed.'
        );
    }

    /**
     * brand_lookup.brand_name has the identical real DB-level UNIQUE
     * constraint (airkingv5.sql:4393) — same fix, same regression test.
     */
    public function test_brands_create_reusing_an_archived_name_returns_422_not_500(): void
    {
        $this->actingAsAdmin();
        $name = $this->uniqueValue();

        $archivedId = $this->postJson('/api/brands', ['brand_name' => $name])
            ->json('data.brand_id');
        $this->deleteJson("/api/brands/{$archivedId}")->assertStatus(200);

        $response = $this->postJson('/api/brands', ['brand_name' => $name]);

        $response->assertStatus(422);
        $this->assertSame(
            1,
            BrandLookup::withTrashed()->where('brand_name', $name)->count(),
            'The DB must still hold exactly the one archived row — the conflicting insert must not have landed.'
        );
    }

    // -----------------------------------------------------------------
    // 8: brands aggregate shape
    // -----------------------------------------------------------------

    /**
     * Pins the aggregate to real numbers (see the matching category test for
     * why assertArrayHasKey alone is not enough). Brand membership comes
     * from appliance_details.brand_id rather than products.category_id, so
     * each product here also needs an ApplianceDetail row.
     */
    public function test_brands_index_returns_aggregate_fields(): void
    {
        $this->actingAsAdmin();
        $brandId = $this->postJson('/api/brands', ['brand_name' => $this->uniqueValue()])
            ->json('data.brand_id');

        $productA = $this->productWithKnownStock(['unit_price' => 100], 5);
        ApplianceDetail::create(['product_id' => $productA->product_id, 'brand_id' => $brandId, 'is_serialized' => true]);

        $productB = $this->productWithKnownStock(['unit_price' => 50], 10);
        ApplianceDetail::create(['product_id' => $productB->product_id, 'brand_id' => $brandId, 'is_serialized' => true]);

        $row = collect($this->getJson('/api/brands')->json('data'))
            ->firstWhere('brand_id', $brandId);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['items_count']);
        $this->assertSame(15, $row['total_units']);
        $this->assertEquals(1000.0, $row['total_value']);
        // Item 7: brand rows now also carry usage_count, uniform with
        // capacity/variant/preset/unit; for brands it equals items_count.
        $this->assertSame(2, $row['usage_count']);
    }

    // -----------------------------------------------------------------
    // 9: brands archive -> archived -> restore
    // -----------------------------------------------------------------

    public function test_brands_archive_then_list_archived_then_restore(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/brands', ['brand_name' => $this->uniqueValue()])
            ->json('data.brand_id');

        $this->deleteJson("/api/brands/{$id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/brands/archived')->json('data'))
            ->pluck('brand_id')->all();
        $this->assertContains($id, $archivedIds);

        $this->postJson("/api/brands/{$id}/restore")->assertStatus(200);

        $activeIds = collect($this->getJson('/api/brands')->json('data'))
            ->pluck('brand_id')->all();
        $this->assertContains($id, $activeIds);
    }

    // -----------------------------------------------------------------
    // 10: force-deleting a unit still in use -> 422 not 500 (app-level guard)
    // -----------------------------------------------------------------

    public function test_force_deleting_a_unit_in_use_is_refused(): void
    {
        $this->actingAsAdmin();

        $unit = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => 'u' . substr(uniqid(), -4),
        ]);

        // Do NOT use LookupTestCase::applianceWith() here — it returns an
        // ApplianceDetail (not a Product) and hardcodes product_type as
        // 'appliance', merging your attributes into the detail row. We need a
        // real installation_material product, so create it directly.
        $productId = DB::table('products')->insertGetId([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Material ' . uniqid(),
            'product_type' => 'installation_material',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('installation_material_details')->insert([
            'product_id'         => $productId,
            'unit_id'            => $unit->unit_id,
            'pieces_per_package' => 10,
            'package_unit'       => 'Box',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->deleteJson("/api/units/{$unit->unit_id}")->assertStatus(200);

        // usageCountFor counts installation_material_details, so the app guard
        // refuses before the DB is asked. This matters more than it used to:
        // the RESTRICT foreign keys that used to back it up lived on the
        // issuable-unit tables, and those are gone.
        $this->deleteJson("/api/units/{$unit->unit_id}/force")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('unit_lookup', ['unit_id' => $unit->unit_id]);
    }
}
