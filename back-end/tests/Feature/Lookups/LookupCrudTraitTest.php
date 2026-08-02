<?php

namespace Tests\Feature\Lookups;

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
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['capacity_value' => 'has already been taken']);
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
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity_value' => 'has already been taken']);
    }

    public function test_two_capacities_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        // Uniqueness used to be scoped per category. With scoping removed it
        // is global — this is the surviving half of the deleted
        // CapacityScopingTest, kept so the guarantee is not silently dropped.
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);

        $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity_value' => 'has already been taken']);
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

    /**
     * lookupUniqueRule() adds ->whereNull('deleted_at') so create/update
     * correctly ignore archived rows when checking for a duplicate name —
     * but that same exemption meant restore() could put a name back into
     * active use on top of an unrelated row that reused it while archived:
     * archive "1.5HP", create a new "1.5HP", then Restore on the archived
     * one produced two active capacities with the same value. This is a
     * trait-level behaviour (HandlesLookupCrud::restore()), so proven once
     * here for all six lookups rather than per-controller.
     */
    public function test_restore_is_blocked_by_an_active_duplicate_name(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        $archivedId = $this->postJson('/api/capacities', ['capacity_value' => $value])
            ->json('data.capacity_id');
        $this->deleteJson("/api/capacities/{$archivedId}")->assertStatus(200);

        // Reuses the now-archived value on a new active row.
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);

        $response = $this->postJson("/api/capacities/{$archivedId}/restore");

        $response->assertStatus(409);

        // The archived row must still be archived (restore did not happen).
        $archivedIds = collect($this->getJson('/api/capacities/archived')->json('data'))
            ->pluck('capacity_id')->all();
        $this->assertContains($archivedId, $archivedIds);
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
        $this->applianceWith(['capacity_rating' => $value]);

        $row = collect($this->getJson('/api/capacities')->json('data'))
            ->firstWhere('capacity_id', $id);

        $this->assertSame(1, $row['usage_count']);
    }

    public function test_usage_count_reflects_a_product_set_row(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');

        $this->productSetWith(['capacity_rating' => $value]);

        $row = collect($this->getJson('/api/capacities')->json('data'))
            ->firstWhere('capacity_id', $id);

        $this->assertSame(1, $row['usage_count']);
    }
}
