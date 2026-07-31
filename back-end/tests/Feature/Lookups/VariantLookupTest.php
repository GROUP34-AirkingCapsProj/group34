<?php

namespace Tests\Feature\Lookups;

use App\Models\VariantLookup;

/**
 * Variant-specific coverage. The CRUD trait itself is already exhaustively
 * proven by LookupCrudTraitTest and RenameCascadeTest against capacity — this
 * only covers what differs for variant: its own column name/width, that its
 * cascade spans both appliance_details and product_sets, and its category
 * scoping.
 */
class VariantLookupTest extends LookupTestCase
{
    /** 1. Create and list a variant. */
    public function test_create_and_list_a_variant(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();

        $created = $this->postJson('/api/variants', ['variant_name' => $value]);
        $created->assertStatus(201);
        $created->assertJsonPath('data.variant_name', $value);

        $list = $this->getJson('/api/variants')->json('data');
        $this->assertNotNull(collect($list)->firstWhere('variant_name', $value));
    }

    /** 2. Category scoping — a category's own values plus globals appear; another category's do not. */
    public function test_category_scoping(): void
    {
        $this->actingAsAdmin();

        $aircon = $this->category();
        $fridge = $this->category();

        $scoped = $this->postJson('/api/variants', [
            'variant_name' => $this->uniqueValue(),
            'category_id'  => $aircon->category_id,
        ])->json('data.variant_id');

        $other = $this->postJson('/api/variants', [
            'variant_name' => $this->uniqueValue(),
            'category_id'  => $fridge->category_id,
        ])->json('data.variant_id');

        $global = $this->postJson('/api/variants', [
            'variant_name' => $this->uniqueValue(),
        ])->json('data.variant_id');

        $ids = collect($this->getJson("/api/variants?category_id={$aircon->category_id}")->json('data'))
            ->pluck('variant_id')->all();

        $this->assertContains($scoped, $ids, "The category's own values must appear");
        $this->assertContains($global, $ids, 'Uncategorised values must appear for every category');
        $this->assertNotContains($other, $ids, "Another category's values must not appear");
    }

    /** 3. Renaming cascades into appliance_details. */
    public function test_renaming_cascades_into_appliance_details(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = VariantLookup::create(['variant_name' => $old]);

        $this->applianceWith(['variant' => $old]);

        $response = $this->putJson("/api/variants/{$lookup->variant_id}", [
            'variant_name' => $new,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('1 items updated', $response->json('message'));

        $this->assertDatabaseMissing('appliance_details', ['variant' => $old]);
        $this->assertDatabaseHas('appliance_details', ['variant' => $new]);
    }

    /**
     * 4. Renaming cascades into product_sets too — this is the test that
     * would catch cascadeTargets() omitting product_sets, letting an in-use
     * value be force-deleted without warning.
     */
    public function test_renaming_cascades_into_product_sets(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = VariantLookup::create(['variant_name' => $old]);

        $this->productSetWith(['variant' => $old]);

        $response = $this->putJson("/api/variants/{$lookup->variant_id}", [
            'variant_name' => $new,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('1 items updated', $response->json('message'));

        $this->assertDatabaseMissing('product_sets', ['variant' => $old]);
        $this->assertDatabaseHas('product_sets', ['variant' => $new]);
    }

    /**
     * 5. The update-scope regression: two variants in one category, PUT the
     * second's name to match the first without sending category_id. Must be
     * rejected with the uniqueness message, and the row must not be renamed.
     */
    public function test_renaming_a_scoped_value_without_resending_category_id_still_checks_its_own_scope(): void
    {
        $this->actingAsAdmin();

        $category = $this->category();
        $first = $this->uniqueValue();
        $second = $this->uniqueValue();

        $firstId = $this->postJson('/api/variants', [
            'variant_name' => $first,
            'category_id'  => $category->category_id,
        ])->json('data.variant_id');

        $secondId = $this->postJson('/api/variants', [
            'variant_name' => $second,
            'category_id'  => $category->category_id,
        ])->json('data.variant_id');

        // No category_id in this payload. If lookupRules() reads the request
        // alone it defaults to the "global" scope and this collision is
        // missed, so the category ends up with two rows named $first.
        $this->putJson("/api/variants/{$secondId}", ['variant_name' => $first])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variant_name' => 'has already been taken']);

        $this->assertDatabaseHas('variant_lookup', [
            'variant_id'   => $secondId,
            'variant_name' => $second,
        ]);
        $this->assertDatabaseHas('variant_lookup', [
            'variant_id'   => $firstId,
            'variant_name' => $first,
        ]);
    }

    /** 6. usage_count is reported non-zero for a variant in use. */
    public function test_usage_count_reported_for_a_variant_in_use(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = VariantLookup::create(['variant_name' => $value]);

        $this->applianceWith(['variant' => $value]);
        $this->productSetWith(['variant' => $value]);

        $response = $this->getJson("/api/variants/{$lookup->variant_id}");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.usage_count'));
    }

    /** 7. sort_order: null is rejected 422, not a 500. */
    public function test_sort_order_null_is_rejected_with_422_not_500(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/variants', [
            'variant_name' => $this->uniqueValue(),
            'sort_order'   => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sort_order']);
    }
}
