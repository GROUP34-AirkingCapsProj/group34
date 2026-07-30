<?php

namespace Tests\Feature\Lookups;

use App\Models\ApplianceDetail;
use App\Models\Product;
use App\Models\ProductSet;

/**
 * Exercises HandlesLookupCrud in full through the capacity endpoint, which is
 * the one lookup that uses every hook. The other five controllers get thin
 * tests covering only what is specific to them.
 */
class LookupCrudTraitTest extends LookupTestCase
{
    public function test_create_returns_201_and_the_new_row(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $response = $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->assertStatus(201);

        $this->assertSame($value, $response->json('data.capacity_value'));
        $this->assertSame(0, $response->json('data.usage_count'));
        $this->assertDatabaseHas('capacity_lookup', ['capacity_value' => $value]);
    }

    public function test_create_rejects_a_duplicate(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_create_rejects_a_duplicate_differing_only_in_case(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);

        // The utf8mb4_unicode_ci collation makes unique validation
        // case-insensitive for free. This test guards that behaviour so a
        // future collation change does not silently allow near-duplicates.
        $this->postJson('/api/capacities', ['capacity_value' => strtolower($value)])
            ->assertStatus(422);
    }

    public function test_create_rejects_a_null_sort_order(): void
    {
        $this->actingAsAdmin();

        // The column is a NOT NULL unsignedTinyInteger default 0. Sending an
        // explicit JSON null (an empty numeric form field, say) must be a
        // clean validation failure, not a SQLSTATE 1048 -> 500.
        $this->postJson('/api/capacities', [
            'capacity_value' => $this->uniqueValue(),
            'sort_order'     => null,
        ])->assertStatus(422);
    }

    public function test_update_changes_the_value(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->json('data.capacity_id');

        $newValue = $this->uniqueValue();

        $this->putJson("/api/capacities/{$id}", ['capacity_value' => $newValue])
            ->assertStatus(200)
            ->assertJsonPath('data.capacity_value', $newValue);
    }

    public function test_archive_then_list_archived_then_restore(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');

        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);

        $archivedIds = collect($this->getJson('/api/capacities/archived')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertContains($id, $archivedIds);

        $activeIds = collect($this->getJson('/api/capacities')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertNotContains($id, $activeIds, 'Archived rows must leave the active list');

        $this->postJson("/api/capacities/{$id}/restore")->assertStatus(200);

        $activeIds = collect($this->getJson('/api/capacities')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertContains($id, $activeIds);
    }

    public function test_force_delete_removes_an_unused_archived_row(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $this->uniqueValue()])
            ->json('data.capacity_id');

        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);
        $this->deleteJson("/api/capacities/{$id}/force")->assertStatus(200);

        $this->assertDatabaseMissing('capacity_lookup', ['capacity_id' => $id]);
    }

    public function test_show_returns_404_for_a_missing_row(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/capacities/99999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_usage_count_reflects_an_appliance_detail_row(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');

        // A usageCountFor() hardcoded to 0 (or one that only reads part of
        // cascadeTargets(), or a non-sargable BINARY predicate that silently
        // matches nothing) would all still pass every other test here.
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Test Appliance',
            'product_type' => 'appliance',
        ]);
        ApplianceDetail::create([
            'product_id'      => $product->product_id,
            'capacity_rating' => $value,
        ]);

        $row = collect($this->getJson('/api/capacities')->json('data'))
            ->firstWhere('capacity_id', $id);

        $this->assertSame(1, $row['usage_count']);
    }

    public function test_usage_count_reflects_a_product_set_row(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');

        ProductSet::create(['capacity_rating' => $value, 'is_active' => true]);

        $row = collect($this->getJson('/api/capacities')->json('data'))
            ->firstWhere('capacity_id', $id);

        $this->assertSame(1, $row['usage_count']);
    }
}
