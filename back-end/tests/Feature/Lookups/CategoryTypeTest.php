<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Lookups\LookupTestCase;

class CategoryTypeTest extends LookupTestCase
{
    /**
     * Categories whose type is neither 'appliance' nor 'installation_material'
     * match neither Item Master dropdown filter and become unselectable. These
     * tests pin the three paths that used to produce such rows.
     */
    public function test_controller_defaults_a_typeless_category_to_appliance(): void
    {
        $this->actingAsAdmin();

        $name = 'Cat ' . uniqid();
        $id = $this->postJson('/api/categories', ['category_name' => $name])
            ->assertStatus(201)
            ->json('data.category_id');

        $this->assertSame('appliance', CategoryLookup::find($id)->category_type);
    }

    public function test_product_import_derives_category_type_from_the_product(): void
    {
        $this->actingAsAdmin();

        $catName = 'Cat ' . uniqid();

        // assertSuccessful, not assertStatus(201): ProductController::store()
        // takes $request->all() with no upfront validate(), so the exact
        // success code is incidental. What this test pins is the category
        // type, and a hard-coded status would make it fail for the wrong
        // reason if store() ever returns 200.
        $this->postJson('/api/products', [
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Material ' . uniqid(),
            'product_type' => 'installation_material',
            'category'     => $catName,
        ])->assertSuccessful();

        $cat = CategoryLookup::where('category_name', $catName)->first();

        $this->assertNotNull($cat, 'the category should have been auto-created');
        $this->assertSame('installation_material', $cat->category_type);
    }

    public function test_product_import_types_an_appliance_category_as_appliance(): void
    {
        $this->actingAsAdmin();

        $catName = 'Cat ' . uniqid();

        // Pins the branch a mutant survived: forcing $categoryType to
        // 'installation_material' unconditionally still passed the
        // installation-material-derivation test above, because that test
        // never exercised the appliance path. product_type omitted here on
        // purpose — the default branch (no product_type / anything other
        // than 'installation_material') must resolve to 'appliance'.
        $this->postJson('/api/products', [
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Appliance ' . uniqid(),
            'category'     => $catName,
        ])->assertSuccessful();

        $cat = CategoryLookup::where('category_name', $catName)->first();

        $this->assertNotNull($cat, 'the category should have been auto-created');
        $this->assertSame('appliance', $cat->category_type);
    }

    public function test_migrated_database_has_no_stray_category_types(): void
    {
        // Queries the live database rather than data this test itself
        // creates, so it can catch a stray value arriving from a seeder or a
        // restored dump. That also means it would pass vacuously on an empty
        // table — assert non-empty first so that case fails loudly instead
        // of silently proving nothing.
        $total = DB::table('category_lookup')->count();
        $this->assertGreaterThan(0, $total, 'category_lookup is empty — this test cannot verify anything');

        // The migration normalises consumable and accessory. Anything else
        // would be invisible in both Item Master dropdowns.
        $stray = DB::table('category_lookup')
            ->whereNotIn('category_type', ['appliance', 'installation_material'])
            ->whereNotNull('category_type')
            ->pluck('category_type')
            ->unique();

        $this->assertEmpty($stray->all(), 'stray category_type values: ' . $stray->implode(', '));
    }

    public function test_an_invalid_category_type_is_rejected(): void
    {
        $this->actingAsAdmin();

        // 'product' is the value that caused the defect: it matched neither
        // Item Master dropdown filter, so the category became unselectable.
        // The in: rule is what stops it recurring through the API.
        $this->postJson('/api/categories', [
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'product',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_type');
    }
}
