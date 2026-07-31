<?php

namespace Tests\Feature\Lookups;

use App\Models\UnitLookup;

/**
 * Covers the three new lookup includes on /api/batch — capacities, variants
 * and issuable_unit_presets — added alongside the existing categories,
 * brands and units includes. Also pins that a warehouse_personnel user (who
 * has none of the lookup_management verbs) can use these includes.
 *
 * That is reachable by construction, not merely "safe": /api/batch carries
 * no permission: route middleware at all, and calls each include's
 * controller index() directly in PHP rather than dispatching through the
 * router, so route-level permission: middleware never runs for anything
 * included here regardless of whether the underlying GET route is gated —
 * this test would pass even if capacities'/variants'/presets' own routes
 * were permission-gated. For the actual route-level proof that those GETs
 * are ungated (i.e. that hitting them directly, through the router, also
 * succeeds for a role with none of the lookup_management verbs), see
 * LookupRouteAccessTest.
 */
class BatchIncludesTest extends LookupTestCase
{
    public function test_batch_returns_the_three_new_includes_as_arrays(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/batch?include=capacities,variants,issuable_unit_presets')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $response->assertJsonPath('data.capacities.success', true);
        $response->assertJsonPath('data.variants.success', true);
        $response->assertJsonPath('data.issuable_unit_presets.success', true);

        $this->assertIsArray($response->json('data.capacities.data'));
        $this->assertIsArray($response->json('data.variants.data'));
        $this->assertIsArray($response->json('data.issuable_unit_presets.data'));
    }

    public function test_created_rows_appear_in_their_batch_payload(): void
    {
        $this->actingAsAdmin();

        $capacityValue = $this->uniqueValue();
        $this->postJson('/api/capacities', ['capacity_value' => $capacityValue])
            ->assertStatus(201);

        $variantValue = $this->uniqueValue();
        $this->postJson('/api/variants', ['variant_name' => $variantValue])
            ->assertStatus(201);

        $unitId = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => null,
        ])->unit_id;

        $presetLabel = $this->uniqueValue();
        $this->postJson('/api/issuable-unit-presets', [
            'label'   => $presetLabel,
            'unit_id' => $unitId,
        ])->assertStatus(201);

        $response = $this->getJson('/api/batch?include=capacities,variants,issuable_unit_presets')
            ->assertStatus(200);

        $capacityValues = collect($response->json('data.capacities.data'))->pluck('capacity_value');
        $this->assertTrue($capacityValues->contains($capacityValue));

        $variantValues = collect($response->json('data.variants.data'))->pluck('variant_name');
        $this->assertTrue($variantValues->contains($variantValue));

        $presets = collect($response->json('data.issuable_unit_presets.data'));
        $preset = $presets->firstWhere('label', $presetLabel);
        $this->assertNotNull($preset, 'created preset not found in batch payload');
    }

    public function test_preset_rows_in_the_batch_carry_their_nested_unit(): void
    {
        $this->actingAsAdmin();

        $unitId = UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => null,
        ])->unit_id;

        $presetLabel = $this->uniqueValue();
        $this->postJson('/api/issuable-unit-presets', [
            'label'   => $presetLabel,
            'unit_id' => $unitId,
        ])->assertStatus(201);

        $response = $this->getJson('/api/batch?include=issuable_unit_presets')
            ->assertStatus(200);

        $presets = collect($response->json('data.issuable_unit_presets.data'));
        $preset = $presets->firstWhere('label', $presetLabel);

        $this->assertNotNull($preset);
        $this->assertArrayHasKey('unit', $preset);
        $this->assertNotNull($preset['unit']);
        $this->assertSame($unitId, $preset['unit']['unit_id']);
    }

    public function test_a_warehouse_personnel_user_can_use_these_includes(): void
    {
        $this->actingAsRole('warehouse_personnel');

        $response = $this->getJson('/api/batch?include=capacities,variants,issuable_unit_presets')
            ->assertStatus(200);

        $response->assertJsonPath('data.capacities.success', true);
        $response->assertJsonPath('data.variants.success', true);
        $response->assertJsonPath('data.issuable_unit_presets.success', true);
    }

    /**
     * capacity/variant inherit ScopesLookupsToCategory::lookupIndexFilters(),
     * which filters by `category_id` on the dedicated /api/capacities and
     * /api/variants endpoints. BatchController must NOT let a stray
     * `category_id` query param on /api/batch itself narrow this include —
     * every other include on this endpoint ignores that param, and a page
     * bootstrapping its full lookup set from batch needs the complete list,
     * not one category's slice. Proven by creating a capacity scoped to a
     * DIFFERENT category than the one named in the query string and
     * asserting it still comes back.
     */
    public function test_batch_capacities_include_ignores_the_category_id_query_param(): void
    {
        $this->actingAsAdmin();

        $categoryA = $this->category();
        $categoryB = $this->category();

        $valueInOtherCategory = $this->uniqueValue();
        $this->postJson('/api/capacities', [
            'capacity_value' => $valueInOtherCategory,
            'category_id'    => $categoryB->category_id,
        ])->assertStatus(201);

        $response = $this->getJson("/api/batch?include=capacities&category_id={$categoryA->category_id}")
            ->assertStatus(200);

        $values = collect($response->json('data.capacities.data'))->pluck('capacity_value');
        $this->assertTrue(
            $values->contains($valueInOtherCategory),
            'batch capacities include must return every capacity, not just category A\'s'
        );
    }

    /**
     * BatchController's `units` include used to return raw UnitLookup models
     * built from a bare query, bypassing UnitLookupController::index() — so
     * it carried no usage_count while every other lookup's batch include
     * (which routes through its controller) did. Now it goes through
     * UnitLookupController::index() like the rest, so usage_count must be
     * present here too.
     */
    public function test_batch_units_include_carries_usage_count(): void
    {
        $this->actingAsAdmin();

        UnitLookup::create([
            'unit_name'         => 'Unit ' . uniqid(),
            'unit_abbreviation' => null,
        ]);

        $response = $this->getJson('/api/batch?include=units')
            ->assertStatus(200)
            ->assertJsonPath('data.units.success', true);

        $rows = collect($response->json('data.units.data'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertArrayHasKey('usage_count', $rows->first());
    }
}
