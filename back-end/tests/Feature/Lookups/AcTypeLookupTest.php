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
}
