<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LocationScopeTest extends TestCase
{
    use DatabaseTransactions;

    /** A real location_id from the test DB (FK-safe). */
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locationId = (int) Location::value('location_id');
    }

    private function makeUser(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username' => 'ls_' . uniqid(),
            'first_name' => 'L', 'last_name' => 'S',
            'email' => uniqid() . '@example.test',
            'password_hash' => bcrypt('secret'),
            'role_id' => $roleId,
            'location_id' => $locationId,
        ]);
    }

    public function test_centralized_roles_have_no_scope(): void
    {
        foreach ([1, 2, 5] as $roleId) {
            $u = $this->makeUser($roleId, $this->locationId);
            $this->assertTrue($u->isCentralized(), "role $roleId should be centralized");
            $this->assertNull($u->scopedLocationId(), "role $roleId should have null scope");
        }
    }

    public function test_scoped_roles_return_their_location(): void
    {
        foreach ([3, 4] as $roleId) {
            $u = $this->makeUser($roleId, $this->locationId);
            $this->assertFalse($u->isCentralized(), "role $roleId should be scoped");
            $this->assertSame($this->locationId, $u->scopedLocationId(), "role $roleId should scope to its location");
        }
    }

    public function test_scoped_user_without_location_returns_null(): void
    {
        $u = $this->makeUser(4, null);
        $this->assertFalse($u->isCentralized());
        $this->assertNull($u->scopedLocationId());
    }
}
