<?php

namespace Tests\Feature\Scoping;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The locations *list* is deliberately unscoped — a scoped user needs to see
 * other locations to pick a transfer destination (see ReferenceUnscopedTest).
 * That decision covers the location's identity (name, code, type), not the
 * operational data hanging off it.
 *
 * GET /locations/{id} eager-loads users, sales, purchaseOrders and adjustments.
 * Those are exactly the location-scoped records every list endpoint filters, so
 * a read-scoped user (warehouse_personnel=4) must not receive them for a
 * location that is not their own. The branch manager reads every location and
 * does receive them — the last test here.
 */
class LocationDetailScopingTest extends TestCase
{
    use DatabaseTransactions;

    /** Relations that expose another location's operations and staff. */
    private const OPERATIONAL = ['users', 'sales', 'purchase_orders', 'adjustments'];

    protected function loc(string $type = 'warehouse'): Location
    {
        return Location::create([
            'location_code' => 'L-' . uniqid(),
            'location_name' => 'Loc ' . uniqid(),
            'location_type' => $type,
        ]);
    }

    protected function userAt(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username'      => 'u_' . uniqid(),
            'first_name'    => 'T',
            'last_name'     => 'U',
            'email'         => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id'       => $roleId,
            'location_id'   => $locationId,
        ]);
    }

    public function test_scoped_user_can_still_identify_another_location(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $wp     = $this->userAt(4, $mine->location_id);

        // The lookup use case must keep working: name/code stay readable.
        $data = $this->actingAs($wp)
            ->getJson('/api/locations/' . $theirs->location_id)
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($theirs->location_name, $data['location_name']);
    }

    public function test_scoped_user_gets_no_operational_data_for_another_location(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        // A staff member stationed at the other location — must not be exposed.
        $this->userAt(4, $theirs->location_id);

        $wp   = $this->userAt(4, $mine->location_id);
        $data = $this->actingAs($wp)
            ->getJson('/api/locations/' . $theirs->location_id)
            ->assertStatus(200)
            ->json('data');

        foreach (self::OPERATIONAL as $rel) {
            $this->assertArrayNotHasKey(
                $rel,
                $data,
                "GET /locations/{id} leaked '{$rel}' for a location the user is not assigned to."
            );
        }
    }

    public function test_scoped_user_still_gets_operational_data_for_their_own_location(): void
    {
        $mine = $this->loc();
        $wp   = $this->userAt(4, $mine->location_id);

        $data = $this->actingAs($wp)
            ->getJson('/api/locations/' . $mine->location_id)
            ->assertStatus(200)
            ->json('data');

        foreach (self::OPERATIONAL as $rel) {
            $this->assertArrayHasKey($rel, $data, "Own location should still expose '{$rel}'.");
        }
    }

    public function test_centralized_user_gets_operational_data_for_any_location(): void
    {
        $a = $this->loc();
        $b = $this->loc();

        // Auditor (role 5) is centralized — sees every location's data.
        $auditor = $this->userAt(5, $a->location_id);
        $data = $this->actingAs($auditor)
            ->getJson('/api/locations/' . $b->location_id)
            ->assertStatus(200)
            ->json('data');

        foreach (self::OPERATIONAL as $rel) {
            $this->assertArrayHasKey($rel, $data, "A centralized role must still see '{$rel}' anywhere.");
        }
    }

    public function test_branch_manager_gets_operational_data_for_another_location(): void
    {
        $mine   = $this->loc();
        $theirs = $this->loc();
        $this->userAt(4, $theirs->location_id);

        $bm   = $this->userAt(3, $mine->location_id);
        $data = $this->actingAs($bm)
            ->getJson('/api/locations/' . $theirs->location_id)
            ->assertStatus(200)
            ->json('data');

        foreach (self::OPERATIONAL as $rel) {
            $this->assertArrayHasKey(
                $rel,
                $data,
                "The branch manager reads every location and must receive '{$rel}' for another one."
            );
        }
    }
}
