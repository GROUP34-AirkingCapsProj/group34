<?php

namespace Tests\Feature\Lookups;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exercises HandlesLookupCrud in full through the capacity endpoint, which is
 * the one lookup that uses every hook. The other five controllers get thin
 * tests covering only what is specific to them.
 */
class LookupCrudTraitTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $roleId = Role::where('role_name', 'admin')->value('role_id');
        $this->assertNotNull($roleId, 'admin role missing - seed the test database');

        $user = User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'Test',
            'last_name'     => 'Admin',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function uniqueValue(): string
    {
        // Must fit capacity_value's varchar(20).
        return 'T' . substr(uniqid(), -8) . 'HP';
    }

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
}
