<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\User;
use App\Traits\ResolvesUserLocation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class ResolveLocationTest extends TestCase
{
    use DatabaseTransactions;

    private object $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new class {
            use ResolvesUserLocation;
            public function call(Request $r): ?int { return $this->resolveLocationId($r); }
            public function err(): string { return $this->locationError; }
        };
    }

    private function makeUser(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username' => 'rl_' . uniqid(), 'first_name' => 'R', 'last_name' => 'L',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => $roleId, 'location_id' => $locationId,
        ]);
    }

    private function request(User $u, array $input = []): Request
    {
        $r = Request::create('/x', 'POST', $input);
        $r->setUserResolver(fn () => $u);
        return $r;
    }

    public function test_scoped_user_forced_to_own_location_ignoring_request(): void
    {
        $loc = Location::value('location_id');
        $wp = $this->makeUser(4, $loc);
        $this->assertSame((int) $loc, $this->harness->call($this->request($wp, ['location_id' => 99999])));
    }

    public function test_scoped_user_without_location_denied(): void
    {
        $bm = $this->makeUser(3, null);
        $this->assertNull($this->harness->call($this->request($bm, ['location_id' => 5])));
        $this->assertNotEmpty($this->harness->err());
    }

    public function test_centralized_user_must_supply_active_location(): void
    {
        $loc = Location::where('is_active', 1)->value('location_id');
        if (! $loc) {
            $location = Location::create([
                'location_code' => 'TST-' . uniqid(),
                'location_name' => 'Test Location',
                'location_type' => 'warehouse',
            ]);
            $loc = $location->location_id;
        }
        $ia = $this->makeUser(2, null);
        $this->assertSame((int) $loc, $this->harness->call($this->request($ia, ['location_id' => $loc])));
    }

    public function test_centralized_user_without_location_denied(): void
    {
        $admin = $this->makeUser(1, null);
        $this->assertNull($this->harness->call($this->request($admin, [])));
    }
}
