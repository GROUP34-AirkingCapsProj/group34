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

    /**
     * 8. max:100 is variant's one divergence from the capacity template.
     * A 100-char value must be accepted; 101 must be rejected with 422 —
     * otherwise MySQL silently truncates on write into appliance_details.variant.
     */
    public function test_variant_name_accepts_100_characters_and_rejects_101(): void
    {
        $this->actingAsAdmin();

        $exactly100 = str_repeat('A', 100);
        $accepted = $this->postJson('/api/variants', ['variant_name' => $exactly100]);
        $accepted->assertStatus(201);
        $accepted->assertJsonPath('data.variant_name', $exactly100);

        $exactly101 = str_repeat('B', 101);
        $rejected = $this->postJson('/api/variants', ['variant_name' => $exactly101]);
        $rejected->assertStatus(422);
        $rejected->assertJsonValidationErrors(['variant_name']);
    }

    /** 9. Merging moves products from the source's value onto the target's, and archives the source. */
    public function test_merge_moves_products_and_archives_the_source(): void
    {
        $this->actingAsAdmin();

        $sourceValue = $this->uniqueValue();
        $targetValue = $this->uniqueValue();

        $source = VariantLookup::create(['variant_name' => $sourceValue]);
        $target = VariantLookup::create(['variant_name' => $targetValue]);

        $this->applianceWith(['variant' => $sourceValue]);
        $this->applianceWith(['variant' => $sourceValue]);
        $this->applianceWith(['variant' => $targetValue]);

        $response = $this->postJson("/api/variants/{$source->variant_id}/merge", [
            'target_id' => $target->variant_id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.moved'));

        $this->assertDatabaseMissing('appliance_details', ['variant' => $sourceValue]);
        $this->assertSame(3, \App\Models\ApplianceDetail::where('variant', $targetValue)->count());

        $list = $this->getJson('/api/variants')->json('data');
        $this->assertNull(
            collect($list)->firstWhere('variant_id', $source->variant_id),
            'A merged source must not remain in the active list'
        );
        $this->assertNotNull(collect($list)->firstWhere('variant_id', $target->variant_id));
    }

    /**
     * 10. Two uncategorised variants cannot share a name. MySQL treats NULLs
     * as distinct in a unique index, so this is enforced in validation via
     * the explicit whereNull('category_id') branch, not the schema.
     */
    public function test_two_uncategorised_variants_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $this->postJson('/api/variants', ['variant_name' => $value])->assertStatus(201);
        $this->postJson('/api/variants', ['variant_name' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variant_name' => 'has already been taken']);
    }
}
