<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();
        return User::create(array_merge([
            'first_name'    => 'Acc',
            'last_name'     => 'User',
            'username'      => 'acc_' . $uid,
            'email'         => 'acc_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ], $overrides));
    }

    public function test_avatar_url_is_null_when_no_avatar_set(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_address_column_persists(): void
    {
        $user = $this->makeUser(['address' => '123 Cooling St']);
        $this->assertSame('123 Cooling St', $user->fresh()->address);
    }
}
