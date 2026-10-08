<?php

namespace Tests\Feature\Lookups;

use App\Models\AcTypeLookup;

/**
 * AC-type-specific coverage. HandlesLookupCrud itself is already proven by
 * LookupCrudTraitTest against capacity; this covers only what differs —
 * the is_two_piece flag and the fact that this lookup is a real foreign key
 * and therefore has no rename cascade.
 */
class AcTypeLookupTest extends LookupTestCase
{
    public function test_the_six_standard_types_are_seeded(): void
    {
        $names = AcTypeLookup::pluck('ac_type_name')->all();

        foreach (['Split Type', 'Window Type', 'Floor Standing', 'Cassette', 'Ducted', 'Portable'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_split_type_defaults_to_two_piece_and_window_type_does_not(): void
    {
        $this->assertTrue((bool) AcTypeLookup::where('ac_type_name', 'Split Type')->value('is_two_piece'));
        $this->assertFalse((bool) AcTypeLookup::where('ac_type_name', 'Window Type')->value('is_two_piece'));
    }

    public function test_is_two_piece_is_cast_to_a_boolean(): void
    {
        $row = AcTypeLookup::create([
            'ac_type_name' => 'T' . substr(uniqid(), -8),
            'is_two_piece' => 1,
        ]);

        $this->assertIsBool($row->fresh()->is_two_piece);
    }

    public function test_create_and_list_an_ac_type(): void
    {
        $this->actingAsAdmin();

        $name = 'T' . substr(uniqid(), -8);

        $this->postJson('/api/ac-types', ['ac_type_name' => $name, 'is_two_piece' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.ac_type_name', $name);

        $list = $this->getJson('/api/ac-types')->json('data');

        $this->assertNotNull(collect($list)->firstWhere('ac_type_name', $name));
    }

    public function test_usage_count_reports_products_carrying_the_type(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);

        $this->applianceWith(['ac_type_id' => $type->ac_type_id]);
        $this->applianceWith(['ac_type_id' => $type->ac_type_id]);

        $row = $this->getJson("/api/ac-types/{$type->ac_type_id}")->json('data');

        $this->assertSame(2, $row['usage_count']);
    }

    public function test_a_type_in_use_cannot_be_force_deleted(): void
    {
        $this->actingAsAdmin();

        $type = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);
        $this->applianceWith(['ac_type_id' => $type->ac_type_id]);

        $this->deleteJson("/api/ac-types/{$type->ac_type_id}")->assertStatus(200);

        $this->deleteJson("/api/ac-types/{$type->ac_type_id}/force")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete: used by 1 items');
    }

    public function test_merging_is_not_supported(): void
    {
        $this->actingAsAdmin();

        $a = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);
        $b = AcTypeLookup::create(['ac_type_name' => 'T' . substr(uniqid(), -8), 'is_two_piece' => true]);

        $this->postJson("/api/ac-types/{$a->ac_type_id}/merge", ['target_id' => $b->ac_type_id])
            ->assertStatus(404);
    }
}
