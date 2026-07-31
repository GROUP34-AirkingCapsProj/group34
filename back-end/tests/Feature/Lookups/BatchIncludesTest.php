<?php

namespace Tests\Feature\Lookups;

use App\Models\UnitLookup;

/**
 * Covers the three new lookup includes on /api/batch — capacities, variants
 * and issuable_unit_presets — added alongside the existing categories,
 * brands and units includes. Also pins that a warehouse_personnel user (who
 * has none of the lookup_management verbs) can use these includes: /api/batch
 * carries no permission: middleware and calls each include's controller
 * index() directly in PHP, so route middleware never runs for it — this is
 * only safe because Part 1 of this task ungated all six lookup reads.
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
}
