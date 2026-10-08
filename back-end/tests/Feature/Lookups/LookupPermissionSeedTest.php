<?php

namespace Tests\Feature\Lookups;

use Database\Seeders\PermissionSeeder;
use Tests\TestCase;

class LookupPermissionSeedTest extends TestCase
{
    public function test_lookup_management_is_in_the_matrix_with_all_four_verbs(): void
    {
        $matrix = PermissionSeeder::matrix();

        $this->assertArrayHasKey('lookup_management', $matrix);

        foreach (['read', 'create', 'update', 'archive'] as $verb) {
            $this->assertArrayHasKey($verb, $matrix['lookup_management'], "Missing verb: {$verb}");
        }
    }

    public function test_lookup_management_mirrors_category_management_exactly(): void
    {
        $matrix = PermissionSeeder::matrix();

        // Deliberate: the new page replaces the categories/brands page, so
        // nobody's effective access should change when it ships.
        $this->assertSame(
            $matrix['category_management'],
            $matrix['lookup_management'],
            'lookup_management must grant the same roles as category_management'
        );
    }
}
