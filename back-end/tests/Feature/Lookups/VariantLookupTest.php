<?php

namespace Tests\Feature\Lookups;

use App\Models\VariantLookup;

/**
 * Variant-specific coverage. The CRUD trait itself is already exhaustively
 * proven by LookupCrudTraitTest and RenameCascadeTest against capacity — this
 * only covers what differs for variant: its own column name/width and that
 * its cascade spans both appliance_details and product_sets.
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

    // 2. "A variant carries no category" belongs with Task 7: the API no
    // longer accepts or scopes on category_id, but variant_lookup.category_id
    // is still a real (always-null) column, so $row->toArray() still emits the
    // key. Assert its absence once the column itself is dropped.

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
     * 10. Two variants cannot share a name. variant_lookup carries no
     * DB-level unique constraint on variant_name, so this is enforced purely
     * in validation.
     */
    public function test_two_variants_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $this->postJson('/api/variants', ['variant_name' => $value])->assertStatus(201);
        $this->postJson('/api/variants', ['variant_name' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variant_name' => 'has already been taken']);
    }
}
