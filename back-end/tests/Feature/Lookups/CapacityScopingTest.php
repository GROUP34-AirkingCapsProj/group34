<?php

namespace Tests\Feature\Lookups;

class CapacityScopingTest extends LookupTestCase
{
    public function test_filtering_by_category_returns_that_category_plus_globals(): void
    {
        $this->actingAsAdmin();

        $aircon = $this->category();
        $fridge = $this->category();

        $scoped = $this->postJson('/api/capacities', [
            'capacity_value' => $this->uniqueValue(),
            'category_id'    => $aircon->category_id,
        ])->json('data.capacity_id');

        $other = $this->postJson('/api/capacities', [
            'capacity_value' => $this->uniqueValue(),
            'category_id'    => $fridge->category_id,
        ])->json('data.capacity_id');

        $global = $this->postJson('/api/capacities', [
            'capacity_value' => $this->uniqueValue(),
        ])->json('data.capacity_id');

        $ids = collect($this->getJson("/api/capacities?category_id={$aircon->category_id}")->json('data'))
            ->pluck('capacity_id')->all();

        $this->assertContains($scoped, $ids, "The category's own values must appear");
        $this->assertContains($global, $ids, 'Uncategorised values must appear for every category');
        $this->assertNotContains($other, $ids, "Another category's values must not appear");
    }

    public function test_the_same_value_may_exist_under_two_categories(): void
    {
        $this->actingAsAdmin();

        $a = $this->category();
        $b = $this->category();
        $value = $this->uniqueValue();

        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $b->category_id])
            ->assertStatus(201);
    }

    public function test_the_same_value_may_not_exist_twice_under_one_category(): void
    {
        $this->actingAsAdmin();

        $a = $this->category();
        $value = $this->uniqueValue();

        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(422);
    }

    public function test_two_uncategorised_rows_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->uniqueValue();

        // MySQL treats NULLs as distinct in a unique index, so this case is
        // enforced in validation rather than by the schema. Without the
        // whereNull branch in lookupRules() this test fails.
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(422);
    }

    public function test_renaming_a_scoped_value_without_resending_category_id_still_checks_its_own_scope(): void
    {
        $this->actingAsAdmin();

        $category = $this->category();
        $first = $this->uniqueValue();
        $second = $this->uniqueValue();

        $firstId = $this->postJson('/api/capacities', [
            'capacity_value' => $first,
            'category_id'    => $category->category_id,
        ])->json('data.capacity_id');

        $secondId = $this->postJson('/api/capacities', [
            'capacity_value' => $second,
            'category_id'    => $category->category_id,
        ])->json('data.capacity_id');

        // No category_id in this payload. If lookupRules() reads the request
        // alone it defaults to the "global" scope and this collision is missed,
        // so the category ends up with two rows named $first.
        $this->putJson("/api/capacities/{$secondId}", ['capacity_value' => $first])
            ->assertStatus(422);

        $this->assertDatabaseHas('capacity_lookup', [
            'capacity_id'    => $firstId,
            'capacity_value' => $first,
        ]);
    }

    public function test_archived_values_do_not_appear_in_a_category_filtered_list(): void
    {
        $this->actingAsAdmin();

        $category = $this->category();
        $value = $this->uniqueValue();

        // This pins that archiving still works once a category filter is on
        // the query. It does NOT pin the nested-closure grouping in
        // lookupIndexFilters(): Eloquent's own scope application groups every
        // pre-scope where before SoftDeletingScope appends its own
        // deleted_at is null (Builder::applyScopes() ->
        // addNewWheresWithinGroup()), so a flat
        // where('category_id', ...)->orWhereNull('category_id') would pass
        // this test too. There is currently no query in this controller
        // where the grouping is load-bearing; it becomes so the moment a
        // second non-scope filter joins the query.
        $id = $this->postJson('/api/capacities', ['capacity_value' => $value])->json('data.capacity_id');
        $this->deleteJson("/api/capacities/{$id}")->assertStatus(200);

        $ids = collect($this->getJson("/api/capacities?category_id={$category->category_id}")->json('data'))
            ->pluck('capacity_id')->all();

        $this->assertNotContains($id, $ids);
    }
}
