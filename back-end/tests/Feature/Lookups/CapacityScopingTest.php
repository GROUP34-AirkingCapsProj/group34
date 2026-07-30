<?php

namespace Tests\Feature\Lookups;

use App\Models\CategoryLookup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CapacityScopingTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): void
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');

        $this->actingAs(User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]));
    }

    private function category(): CategoryLookup
    {
        return CategoryLookup::create([
            'category_name' => 'Cat ' . uniqid(),
            'category_type' => 'product',
        ]);
    }

    private function value(): string
    {
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

    public function test_filtering_by_category_returns_that_category_plus_globals(): void
    {
        $this->actingAsAdmin();

        $aircon = $this->category();
        $fridge = $this->category();

        $scoped = $this->postJson('/api/capacities', [
            'capacity_value' => $this->value(),
            'category_id'    => $aircon->category_id,
        ])->json('data.capacity_id');

        $other = $this->postJson('/api/capacities', [
            'capacity_value' => $this->value(),
            'category_id'    => $fridge->category_id,
        ])->json('data.capacity_id');

        $global = $this->postJson('/api/capacities', [
            'capacity_value' => $this->value(),
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
        $value = $this->value();

        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $b->category_id])
            ->assertStatus(201);
    }

    public function test_the_same_value_may_not_exist_twice_under_one_category(): void
    {
        $this->actingAsAdmin();

        $a = $this->category();
        $value = $this->value();

        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value, 'category_id' => $a->category_id])
            ->assertStatus(422);
    }

    public function test_two_uncategorised_rows_may_not_share_a_value(): void
    {
        $this->actingAsAdmin();
        $value = $this->value();

        // MySQL treats NULLs as distinct in a unique index, so this case is
        // enforced in validation rather than by the schema. Without the
        // whereNull branch in lookupRules() this test fails.
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(201);
        $this->postJson('/api/capacities', ['capacity_value' => $value])->assertStatus(422);
    }
}
