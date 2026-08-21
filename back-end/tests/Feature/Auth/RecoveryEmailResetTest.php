<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RecoveryEmailResetTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'    => 'Reco',
            'last_name'     => 'Very',
            'username'      => 'reco_' . $uid,
            'email'         => 'reco_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ], $overrides));
    }

    public function test_reset_mail_address_prefers_recovery_email(): void
    {
        $user = $this->makeUser(['recovery_email' => 'personal@gmail.com']);

        $this->assertSame('personal@gmail.com', $user->reset_mail_address);
        $this->assertSame('personal@gmail.com', $user->routeNotificationForMail(null));
    }

    public function test_reset_mail_address_falls_back_to_login_email(): void
    {
        $user = $this->makeUser(['recovery_email' => null]);

        $this->assertSame($user->email, $user->reset_mail_address);
        $this->assertSame($user->email, $user->routeNotificationForMail(null));
    }

    public function test_new_users_must_change_password_by_default(): void
    {
        $user = $this->makeUser();

        $this->assertTrue($user->fresh()->must_change_password);
    }
}
