<?php

namespace Tests\Feature\Lookups;

use App\Models\ApplianceDetail;
use App\Models\CapacityLookup;
use App\Models\Product;
use App\Models\ProductSet;
use Illuminate\Foundation\Testing\DatabaseTransactions;

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
    use DatabaseTransactions;

    /** Creates an appliance product carrying the given capacity value. */
    protected function applianceWithCapacity(string $capacity): ApplianceDetail
    {
        $product = Product::create([
            'product_code' => 'PC-' . uniqid(),
            'product_name' => 'Test Appliance ' . uniqid(),
            'product_type' => 'appliance',
        ]);

        return ApplianceDetail::create([
            'product_id'      => $product->product_id,
            'capacity_rating' => $capacity,
            'is_serialized'   => true,
        ]);
    }

    /** Creates a product set carrying the given capacity value. */
    protected function productSetWithCapacity(string $capacity): ProductSet
    {
        return ProductSet::create([
            'capacity_rating' => $capacity,
            'is_active'       => true,
        ]);
    }

    /** 1. Renaming updates every product and reports the count. */
    public function test_renaming_updates_every_product_and_reports_the_count(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = CapacityLookup::create(['capacity_value' => $old]);

        $this->applianceWithCapacity($old);
        $this->applianceWithCapacity($old);

        $response = $this->putJson("/api/capacities/{$lookup->capacity_id}", [
            'capacity_value' => $new,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('2 items updated', $response->json('message'));

        $this->assertSame(0, ApplianceDetail::whereRaw('BINARY capacity_rating = ?', [$old])->count());
        $this->assertSame(2, ApplianceDetail::whereRaw('BINARY capacity_rating = ?', [$new])->count());
    }

    /** 2. The cascade spans product_sets too. */
    public function test_cascade_spans_product_sets(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = CapacityLookup::create(['capacity_value' => $old]);

        $this->applianceWithCapacity($old);
        $this->productSetWithCapacity($old);

        $response = $this->putJson("/api/capacities/{$lookup->capacity_id}", [
            'capacity_value' => $new,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('2 items updated', $response->json('message'));

        $this->assertSame(0, ProductSet::whereRaw('BINARY capacity_rating = ?', [$old])->count());
        $this->assertSame(1, ProductSet::whereRaw('BINARY capacity_rating = ?', [$new])->count());
    }

    /** 3. Usage count is reported on the list. */
    public function test_usage_count_is_reported_on_the_list(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $this->applianceWithCapacity($value);

        $response = $this->getJson('/api/capacities');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('capacity_id', $lookup->capacity_id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['usage_count']);
    }

    /** 4. Renaming an unused value reports no item count. */
    public function test_renaming_an_unused_value_reports_no_item_count(): void
    {
        $this->actingAsAdmin();

        $old = $this->uniqueValue();
        $new = $this->uniqueValue();

        $lookup = CapacityLookup::create(['capacity_value' => $old]);

        $response = $this->putJson("/api/capacities/{$lookup->capacity_id}", [
            'capacity_value' => $new,
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('items updated', $response->json('message'));
    }

    /** 5. Renaming onto an existing value is rejected with 422. */
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

        $response->assertStatus(422);
    }

    /** 6. Merge moves products and archives the source. */
    public function test_merge_moves_products_and_archives_the_source(): void
    {
        $this->actingAsAdmin();

        $sourceValue = $this->uniqueValue();
        $targetValue = $this->uniqueValue();

        $source = CapacityLookup::create(['capacity_value' => $sourceValue]);
        $target = CapacityLookup::create(['capacity_value' => $targetValue]);

        $this->applianceWithCapacity($sourceValue);
        $this->applianceWithCapacity($sourceValue);
        $this->applianceWithCapacity($targetValue);

        $response = $this->postJson("/api/capacities/{$source->capacity_id}/merge", [
            'target_id' => $target->capacity_id,
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.moved'));

        $this->assertSame(0, ApplianceDetail::whereRaw('BINARY capacity_rating = ?', [$sourceValue])->count());
        $this->assertSame(3, ApplianceDetail::whereRaw('BINARY capacity_rating = ?', [$targetValue])->count());

        $list = $this->getJson('/api/capacities')->json('data');
        $this->assertNull(collect($list)->firstWhere('capacity_id', $source->capacity_id));
        $this->assertNotNull(collect($list)->firstWhere('capacity_id', $target->capacity_id));
    }

    /** 7. Merging a value into itself is rejected. */
    public function test_merging_a_value_into_itself_is_rejected(): void
    {
        $this->actingAsAdmin();

        $lookup = CapacityLookup::create(['capacity_value' => $this->uniqueValue()]);

        $response = $this->postJson("/api/capacities/{$lookup->capacity_id}/merge", [
            'target_id' => $lookup->capacity_id,
        ]);

        $response->assertStatus(422);
    }

    /** 8. Merging into a non-existent target returns 404. */
    public function test_merging_into_a_nonexistent_target_returns_404(): void
    {
        $this->actingAsAdmin();

        $lookup = CapacityLookup::create(['capacity_value' => $this->uniqueValue()]);

        $response = $this->postJson("/api/capacities/{$lookup->capacity_id}/merge", [
            'target_id' => 999999999,
        ]);

        $response->assertStatus(404);
    }

    /** 9. Archiving a value that is in use succeeds and reports the count. */
    public function test_archiving_a_value_in_use_succeeds_and_reports_the_count(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $appliance = $this->applianceWithCapacity($value);

        $response = $this->deleteJson("/api/capacities/{$lookup->capacity_id}");

        $response->assertOk();
        $this->assertSame(1, $response->json('data.usage_count'));

        $this->assertSame($value, $appliance->fresh()->capacity_rating);
    }

    /** 10. Force-deleting a value that is in use is refused. */
    public function test_force_deleting_a_value_in_use_is_refused(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $this->applianceWithCapacity($value);

        $lookup->delete();

        $response = $this->deleteJson("/api/capacities/{$lookup->capacity_id}/force");

        $response->assertStatus(422);
        $this->assertNotNull(CapacityLookup::withTrashed()->find($lookup->capacity_id));
    }

    /** 11. An archived value still on an item survives a round trip. */
    public function test_an_archived_value_still_on_an_item_survives(): void
    {
        $this->actingAsAdmin();

        $value = $this->uniqueValue();
        $lookup = CapacityLookup::create(['capacity_value' => $value]);
        $appliance = $this->applianceWithCapacity($value);

        $this->deleteJson("/api/capacities/{$lookup->capacity_id}")->assertOk();

        $this->assertSame($value, $appliance->fresh()->capacity_rating);
    }

    /** 12. The rename cascade is case-sensitive. */
    public function test_rename_cascade_is_case_sensitive(): void
    {
        $this->actingAsAdmin();

        $upper = 'AAA';
        $lower = 'aaa';
        $renamedUpper = $this->uniqueValue();

        $lookupUpper = CapacityLookup::create(['capacity_value' => $upper]);
        // Created directly on the model: the API's uniqueness check is
        // case-insensitive, so a second row differing only in case could
        // not be created through the endpoint.
        $lookupLower = CapacityLookup::create(['capacity_value' => $lower]);

        $applianceUpper = $this->applianceWithCapacity($upper);
        $applianceLower = $this->applianceWithCapacity($lower);

        $response = $this->putJson("/api/capacities/{$lookupUpper->capacity_id}", [
            'capacity_value' => $renamedUpper,
        ]);

        $response->assertOk();

        $this->assertSame(
            $lower,
            ApplianceDetail::whereRaw('BINARY capacity_rating = ?', [$lower])
                ->where('appliance_detail_id', $applianceLower->appliance_detail_id)
                ->value('capacity_rating')
        );

        $this->assertSame(
            $renamedUpper,
            ApplianceDetail::whereRaw('BINARY capacity_rating = ?', [$renamedUpper])
                ->where('appliance_detail_id', $applianceUpper->appliance_detail_id)
                ->value('capacity_rating')
        );
    }
}
