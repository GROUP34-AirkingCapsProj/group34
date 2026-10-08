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

    public function test_forgot_sends_otp_to_the_submitted_recovery_email(): void
    {
        Mail::fake();
        $recovery = 'personal_' . uniqid() . '@gmail.com';
        $this->makeUser(['recovery_email' => $recovery]);

        $this->postJson('/api/forgot-password', ['email' => $recovery])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($recovery) {
            return $mail->hasTo($recovery);
        });
    }

    public function test_forgot_matches_the_recovery_email_case_insensitively(): void
    {
        Mail::fake();
        $recovery = 'mixedcase_' . uniqid() . '@gmail.com';
        $this->makeUser(['recovery_email' => $recovery]);

        $this->postJson('/api/forgot-password', ['email' => strtoupper($recovery)])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($recovery) {
            return $mail->hasTo($recovery);
        });
    }

    public function test_forgot_refuses_the_login_email_as_the_identifier(): void
    {
        Mail::fake();
        $user = $this->makeUser(['recovery_email' => 'personal_' . uniqid() . '@gmail.com']);

        // Submitting the company address the employee is locked out of must not
        // start a reset -- the recovery address is the only accepted identifier.
        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($m) => is_string($m));

        Mail::assertNothingSent();
        $this->assertSame(0, PasswordResetOtp::where('email', $user->email)->count());
    }

    public function test_forgot_never_falls_back_to_the_login_inbox(): void
    {
        Mail::fake();
        $user = $this->makeUser(['recovery_email' => null]);

        // No recovery address on file: this account cannot self-recover at all.
        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($m) => is_string($m));

        Mail::assertNothingSent();
        $this->assertSame(0, PasswordResetOtp::where('email', $user->email)->count());
    }

    public function test_full_reset_flow_is_keyed_on_the_recovery_email(): void
    {
        Mail::fake();
        $recovery = 'flow_' . uniqid() . '@gmail.com';
        $user = $this->makeUser(['recovery_email' => $recovery]);

        $this->postJson('/api/forgot-password', ['email' => $recovery])
            ->assertStatus(200);

        $otp = null;
        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });
        $this->assertNotNull($otp);

        $verify = $this->postJson('/api/verify-reset-otp', [
            'email' => $recovery,
            'otp' => $otp,
        ])->assertStatus(200);

        $token = $verify->json('reset_token');
        $this->assertNotEmpty($token);

        $this->postJson('/api/reset-password', [
            'email' => $recovery,
            'reset_token' => $token,
            'password' => 'BrandNew123!',
            'password_confirmation' => 'BrandNew123!',
        ])->assertStatus(200);

        $this->assertTrue(password_verify('BrandNew123!', $user->fresh()->password_hash));
        $this->assertSame(0, PasswordResetOtp::where('email', $recovery)->count());
    }

    public function test_forgot_names_an_unregistered_address_as_unregistered(): void
    {
        Mail::fake();

        $knownRecovery = 'personal_' . uniqid() . '@gmail.com';
        $this->makeUser(['recovery_email' => $knownRecovery]);
        $unknownEmail = 'nobody_' . uniqid() . '@airking.com';

        $this->postJson('/api/forgot-password', ['email' => $knownRecovery])
            ->assertStatus(200);

        // Product decision: a miss is named rather than hidden, so a user who
        // mistyped finds out immediately. This intentionally makes account
        // membership probeable -- see the note in ForgotPasswordOtpController.
        $this->postJson('/api/forgot-password', ['email' => $unknownEmail])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($m) => is_string($m));

        // No challenge row for the address that does not exist, and only the
        // real account was mailed.
        $this->assertSame(0, PasswordResetOtp::where('email', $unknownEmail)->count());
        Mail::assertSentCount(1);
    }

    public function test_forgot_rejects_a_malformed_address(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($m) => is_string($m));

        Mail::assertNothingSent();
    }
}
