<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
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

    public function test_forgot_sends_otp_to_recovery_email(): void
    {
        Mail::fake();
        $user = $this->makeUser(['recovery_email' => 'personal@gmail.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) {
            return $mail->hasTo('personal@gmail.com');
        });
    }

    public function test_forgot_falls_back_to_login_email_when_no_recovery_address(): void
    {
        Mail::fake();
        $user = $this->makeUser(['recovery_email' => null]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_forgot_does_not_reveal_whether_an_account_exists(): void
    {
        Mail::fake();

        $known = $this->makeUser(['recovery_email' => 'personal@gmail.com']);
        $unknownEmail = 'nobody_' . uniqid() . '@airking.com';

        $hit = $this->postJson('/api/forgot-password', ['email' => $known->email]);
        $miss = $this->postJson('/api/forgot-password', ['email' => $unknownEmail]);

        $hit->assertStatus(200);
        $miss->assertStatus(200);
        // Byte-identical bodies: nothing distinguishes a real account from a miss.
        $this->assertSame($hit->json(), $miss->json());

        // And no challenge row is created for the address that does not exist.
        $this->assertSame(0, PasswordResetOtp::where('email', $unknownEmail)->count());
        Mail::assertSentCount(1);
    }
}
