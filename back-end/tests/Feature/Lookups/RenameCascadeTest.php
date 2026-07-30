<?php

namespace Tests\Feature\Lookups;

use App\Models\ApplianceDetail;
use App\Models\CapacityLookup;

/**
 * End-to-end proof that renaming/merging a capacity lookup value cascades
 * onto the plain-string columns that reference it (appliance_details and
 * product_sets), and that archiving/force-delete never touch those strings.
 *
 * Capacity is the lookup used here because its controller is the one built
 * in Task 6, but the behaviour under test lives in LookupRenameService
 * (Task 3) and HandlesLookupCrud (Task 4) — shared by every lookup that
 * opts into supportsMerge().
 */
class RenameCascadeTest extends LookupTestCase
{
    /** 1. Renaming updates every product and reports the count. */
    public function test_renaming_updates_every_product_and_reports_the_count(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = CapacityLookup::create(['capacity_value' => $old]);

        $this->applianceWith(['capacity_rating' => $old]);
        $this->applianceWith(['capacity_rating' => $old]);

        $response = $this->putJson("/api/capacities/{$lookup->capacity_id}", [
            'capacity_value' => $new,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('2 items updated', $response->json('message'));

        $this->assertDatabaseMissing('appliance_details', ['capacity_rating' => $old]);
        $this->assertSame(2, ApplianceDetail::where('capacity_rating', $new)->count());
    }

    /** 2. The cascade spans product_sets too. */
    public function test_cascade_spans_product_sets(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = CapacityLookup::create(['capacity_value' => $old]);

        $this->applianceWith(['capacity_rating' => $old]);
        $this->productSetWith(['capacity_rating' => $old]);

        $response = $this->putJson("/api/capacities/{$lookup->capacity_id}", [
            'capacity_value' => $new,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('2 items updated', $response->json('message'));

        $this->assertDatabaseMissing('product_sets', ['capacity_rating' => $old]);
        $this->assertDatabaseHas('product_sets', ['capacity_rating' => $new]);
    }

    /** 3. Renaming an unused value reports no item count. */
    public function test_renaming_an_unused_value_reports_no_item_count(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = CapacityLookup::create(['capacity_value' => $old]);

        $response = $this->putJson("/api/capacities/{$lookup->capacity_id}", [
            'capacity_value' => $new,
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString('items updated', $response->json('message'));
    }

    /** 4. Renaming onto an existing value is rejected with 422, and nothing changes. */
    public function test_renaming_onto_an_existing_value_is_rejected(): void
    {
        $this->actingAsAdmin();

        $valueA = $this->uniqueValue();
        $valueB = $this->uniqueValue();

        CapacityLookup::create(['capacity_value' => $valueA]);
        $lookupB = CapacityLookup::create(['capacity_value' => $valueB]);

        $response = $this->putJson("/api/capacities/{$lookupB->capacity_id}", [
            'capacity_value' => $valueA,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('capacity_value');

        $this->assertDatabaseHas('capacity_lookup', [
            'capacity_id'    => $lookupB->capacity_id,
            'capacity_value' => $valueB,
        ]);
    }

    /** 5. Merge moves products and archives the source. */
    public function test_merge_moves_products_and_archives_the_source(): void
    {
        $this->actingAsAdmin();

        $sourceValue = $this->uniqueValue();
        $targetValue = $this->uniqueValue();

        $source = CapacityLookup::create(['capacity_value' => $sourceValue]);
        $target = CapacityLookup::create(['capacity_value' => $targetValue]);

        $this->applianceWith(['capacity_rating' => $sourceValue]);
        $this->applianceWith(['capacity_rating' => $sourceValue]);
        $this->applianceWith(['capacity_rating' => $targetValue]);

        $response = $this->postJson("/api/capacities/{$source->capacity_id}/merge", [
            'target_id' => $target->capacity_id,
        ]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.moved'));

        $this->assertDatabaseMissing('appliance_details', ['capacity_rating' => $sourceValue]);
        $this->assertSame(3, ApplianceDetail::where('capacity_rating', $targetValue)->count());

        $list = $this->getJson('/api/capacities')->json('data');
        $this->assertNull(
            collect($list)->firstWhere('capacity_id', $source->capacity_id),
            'A merged source must not remain in the active list'
        );
        $this->assertNotNull(collect($list)->firstWhere('capacity_id', $target->capacity_id));
    }

    /** 6. Merging a value into itself is rejected. */
    public function test_merging_a_value_into_itself_is_rejected(): void
    {
        $this->actingAsAdmin();

        $lookup = CapacityLookup::create(['capacity_value' => $this->uniqueValue()]);

        $response = $this->postJson("/api/capacities/{$lookup->capacity_id}/merge", [
            'target_id' => $lookup->capacity_id,
        ]);

        $response->assertStatus(422);
    }

    /** 7. Merging into a non-existent target returns 404 naming the target. */
    public function test_merging_into_a_nonexistent_target_returns_404(): void
    {
        $this->actingAsAdmin();

        $lookup = CapacityLookup::create(['capacity_value' => $this->uniqueValue()]);

        $response = $this->postJson("/api/capacities/{$lookup->capacity_id}/merge", [
            'target_id' => 999999999,
        ]);

        $response->assertStatus(404);
        $this->assertStringContainsString(
            'Target',
            $response->json('message'),
            'A 404 here could otherwise mean "source not found" instead of "target not found"'
        );
    }

    /** 8. Archiving a value that is in use succeeds and reports the count, without touching the product. */
    public function test_archiving_a_value_in_use_succeeds_and_reports_the_count(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $appliance = $this->applianceWith(['capacity_rating' => $value]);

        $response = $this->deleteJson("/api/capacities/{$lookup->capacity_id}");

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.usage_count'));

        $this->assertDatabaseHas('appliance_details', [
            'appliance_detail_id' => $appliance->appliance_detail_id,
            'capacity_rating'     => $value,
        ]);
    }

    /** 9. Force-deleting a value that is in use is refused, and both the lookup and the product survive. */
    public function test_force_deleting_a_value_in_use_is_refused(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $appliance = $this->applianceWith(['capacity_rating' => $value]);

        $lookup->delete();

        $response = $this->deleteJson("/api/capacities/{$lookup->capacity_id}/force");

        $response->assertStatus(422);
        $this->assertNotNull(CapacityLookup::withTrashed()->find($lookup->capacity_id));
        $this->assertSame($value, $appliance->fresh()->capacity_rating);
    }

    /** 10. Restore returns the value to the active list without ever having touched the product string. */
    public function test_restore_returns_the_product_string_and_usage_count(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $appliance = $this->applianceWith(['capacity_rating' => $value]);

        $this->deleteJson("/api/capacities/{$lookup->capacity_id}")->assertStatus(200);

        $response = $this->postJson("/api/capacities/{$lookup->capacity_id}/restore");

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.usage_count'));
        $this->assertSame($value, $appliance->fresh()->capacity_rating);
    }

    /** 11. The rename cascade is case-sensitive. */
    public function test_rename_cascade_is_case_sensitive(): void
    {
        $this->actingAsAdmin();

        $upper = $this->uniqueValue();
        $lower = strtolower($upper);
        $renamedUpper = $this->uniqueValue();

        $lookupUpper = CapacityLookup::create(['capacity_value' => $upper]);
        // Created directly on the model: the API's uniqueness check is
        // case-insensitive, so a second row differing only in case could
        // not be created through the endpoint.
        CapacityLookup::create(['capacity_value' => $lower]);

        $applianceUpper = $this->applianceWith(['capacity_rating' => $upper]);
        $applianceLower = $this->applianceWith(['capacity_rating' => $lower]);

        $response = $this->putJson("/api/capacities/{$lookupUpper->capacity_id}", [
            'capacity_value' => $renamedUpper,
        ]);

        $response->assertStatus(200);

        $this->assertSame(
            $lower,
            $applianceLower->fresh()->capacity_rating,
            'A case-insensitive match would rewrite the lowercase row too'
        );
        $this->assertSame($renamedUpper, $applianceUpper->fresh()->capacity_rating);
    }
}
