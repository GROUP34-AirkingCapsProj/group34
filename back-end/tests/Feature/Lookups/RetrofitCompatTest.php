<?php

namespace Tests\Feature\Lookups;

use App\Models\BrandLookup;
use App\Models\CategoryLookup;
use App\Models\IssuableUnitPreset;
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
     * unit_abbreviation is a NOT NULL varchar(10) column with no default at
     * the DB layer (pre-existing schema fact, unrelated to this retrofit),
     * so every POST /api/units in this file must supply one or the insert
     * 500s with SQLSTATE 1364 regardless of what the validation rule says.
     */
    protected function createUnit(?string $name = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/units', [
            'unit_name'         => $name ?? $this->uniqueValue(),
            'unit_abbreviation' => 'u',
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

    public function test_categories_index_returns_aggregate_fields(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/categories', [
            'category_name' => $this->uniqueValue(),
        ])->assertStatus(201);

        $id = $response->json('data.category_id');

        $row = collect($this->getJson('/api/categories')->json('data'))
            ->firstWhere('category_id', $id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('items_count', $row);
        $this->assertArrayHasKey('total_units', $row);
        $this->assertArrayHasKey('total_value', $row);
        $this->assertArrayHasKey('avg_price', $row);
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

    // -----------------------------------------------------------------
    // 8: brands aggregate shape
    // -----------------------------------------------------------------

    public function test_brands_index_returns_aggregate_fields(): void
    {
        $this->actingAsAdmin();

        $id = $this->postJson('/api/brands', ['brand_name' => $this->uniqueValue()])
            ->json('data.brand_id');

        $row = collect($this->getJson('/api/brands')->json('data'))
            ->firstWhere('brand_id', $id);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('items_count', $row);
        $this->assertArrayHasKey('total_units', $row);
        $this->assertArrayHasKey('total_value', $row);
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
    // 10: force-deleting a unit still referenced by a preset -> 422 not 500
    // -----------------------------------------------------------------

    public function test_force_delete_unit_referenced_by_preset_returns_422_not_500(): void
    {
        $this->actingAsAdmin();
        $unit = $this->unit();

        IssuableUnitPreset::create([
            'label'   => $this->uniqueValue(),
            'unit_id' => $unit->unit_id,
        ]);

        $this->deleteJson("/api/units/{$unit->unit_id}")->assertStatus(200);

        $response = $this->deleteJson("/api/units/{$unit->unit_id}/force");

        $response->assertStatus(422);
        $this->assertDatabaseHas('unit_lookup', ['unit_id' => $unit->unit_id]);
    }
}
