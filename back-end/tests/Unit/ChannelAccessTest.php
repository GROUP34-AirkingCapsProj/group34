<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\ChannelAccess;
use PHPUnit\Framework\TestCase;

class ChannelAccessTest extends TestCase
{
    private function user(int $roleId, ?int $locationId): User
    {
        $u = new User();
        $u->role_id = $roleId;
        $u->location_id = $locationId;
        return $u;
    }

    /**
      * Channel access follows the READ scope (User::readsAllLocations), which
      * includes branch_manager=3 — a channel carries data to look at, and that
      * role looks at every location even though it writes only at its own.
      */
    public function test_all_location_readers_access_org(): void
    {
        foreach ([1, 2, 3, 5] as $roleId) {
            $this->assertTrue(ChannelAccess::canAccessOrg($this->user($roleId, null)));
        }
    }

    public function test_the_read_scoped_role_cannot_access_org(): void
    {
        $this->assertFalse(ChannelAccess::canAccessOrg($this->user(4, 7)));
    }

    public function test_an_all_location_reader_can_access_any_location(): void
    {
        $this->assertTrue(ChannelAccess::canAccessLocation($this->user(1, null), 99));
        $this->assertTrue(
            ChannelAccess::canAccessLocation($this->user(3, 7), 99),
            'The branch manager reads every location, so no location channel is closed to it.'
        );
    }

    public function test_scoped_user_only_accesses_own_location(): void
    {
        $u = $this->user(4, 7);
        $this->assertTrue(ChannelAccess::canAccessLocation($u, 7));
        $this->assertFalse(ChannelAccess::canAccessLocation($u, 8));
    }

    public function test_scoped_user_with_no_location_denied(): void
    {
        $this->assertFalse(ChannelAccess::canAccessLocation($this->user(4, null), 7));
    }
}
