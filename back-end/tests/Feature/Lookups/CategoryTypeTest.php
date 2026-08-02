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

    public function test_no_category_carries_a_type_outside_the_two_valid_values(): void
    {
        // The migration normalises consumable and accessory. Anything else
        // would be invisible in both Item Master dropdowns.
        $stray = DB::table('category_lookup')
            ->whereNotIn('category_type', ['appliance', 'installation_material'])
            ->whereNotNull('category_type')
            ->pluck('category_type')
            ->unique();

        $this->assertEmpty($stray->all(), 'stray category_type values: ' . $stray->implode(', '));
    }
}
