<?php

namespace Tests\Feature\Inventory;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Models\Issuance;

/**
 * Regression: the issuances index eager-loaded a non-existent `issuedBy` relation
 * (the relation is `issuedByUser`), which made GET /api/issuances return 500.
 */
class IssuanceIndexLoadsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => 1, // admin (centralized)
            'location_id'   => null,
        ]);
    }

    public function test_issuances_index_returns_200_with_records(): void
    {
        $loc = Location::create(['location_code' => 'L-' . uniqid(), 'location_name' => 'Loc', 'location_type' => 'warehouse']);
        Issuance::create([
            'location_id'   => $loc->location_id,
            'issuance_date' => now()->toDateString(),
            'status_id'     => 1,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/issuances')
            ->assertStatus(200);
    }
}
