<?php

namespace Tests\Feature\Permissions;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ForUserScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId, ?int $locationId): User
    {
        return User::create([
            'username' => 'fu_' . uniqid(), 'first_name' => 'F', 'last_name' => 'U',
            'email' => uniqid() . '@example.test', 'password_hash' => bcrypt('secret'),
            'role_id' => $roleId, 'location_id' => $locationId,
        ]);
    }

    public function test_centralized_user_query_is_unfiltered(): void
    {
        $admin = $this->makeUser(1, null);
        $sql = Inventory::query()->forUser($admin)->toSql();
        $this->assertStringNotContainsString('location_id', $sql);
    }

    public function test_scoped_user_query_filters_by_location(): void
    {
        $locId = Location::value('location_id');
        $wp = $this->makeUser(4, $locId);
        $sql = strtolower(Inventory::query()->forUser($wp)->toSql());
        $this->assertStringContainsString('location_id', $sql);
    }

    public function test_scoped_user_without_location_returns_empty(): void
    {
        $wp = $this->makeUser(4, null);
        $sql = Inventory::query()->forUser($wp)->toSql();
        $this->assertStringContainsString('1 = 0', $sql);
    }
}
