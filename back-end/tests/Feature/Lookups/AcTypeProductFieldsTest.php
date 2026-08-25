<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;
use App\Models\Product;

/**
 * ProductController must accept, persist and return the two new
 * appliance_details fields. Without the flattener change the fields save but
 * never come back, which reads to the frontend as "the save silently failed".
 */
class AcTypeProductFieldsTest extends LookupTestCase
{
    public function test_registering_an_appliance_stores_ac_type_and_unit_role(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();
        $category = $this->category();

        $response = $this->postJson('/api/products', [
            'product_name' => 'Test Split ' . uniqid(),
            'product_code' => 'PC-' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
            'ac_type_id'   => $type->ac_type_id,
            'unit_role'    => 'indoor',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ac_type_id', $type->ac_type_id);
        $response->assertJsonPath('data.unit_role', 'indoor');
    }

    public function test_updating_an_appliance_changes_the_unit_role(): void
    {
        $this->actingAsAdmin();

        $detail = $this->applianceWith(['unit_role' => 'indoor']);

        $response = $this->putJson("/api/products/{$detail->product_id}", [
            'unit_role' => 'outdoor',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.unit_role', 'outdoor');
    }

    public function test_an_invalid_unit_role_is_rejected(): void
    {
        $this->actingAsAdmin();

        $category = $this->category();

        $this->postJson('/api/products', [
            'product_name' => 'Bad Role ' . uniqid(),
            'product_code' => 'PC-' . uniqid(),
            'product_type' => 'appliance',
            'category_id'  => $category->category_id,
            'unit_role'    => 'sideways',
        ])->assertStatus(422);
    }

    public function test_a_non_appliance_ignores_the_ac_fields(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::where('ac_type_name', 'Split Type')->firstOrFail();

        $response = $this->postJson('/api/products', [
            'product_name'  => 'Test Material ' . uniqid(),
            'product_code'  => 'PC-' . uniqid(),
            'product_type'  => 'installation_material',
            'ac_type_id'    => $type->ac_type_id,
            'unit_role'     => 'indoor',
        ]);

        $response->assertStatus(201);

        $product = Product::find($response->json('data.product_id'));

        $this->assertNull($product->applianceDetail);
    }
}
