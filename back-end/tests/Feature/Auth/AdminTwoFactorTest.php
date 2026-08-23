<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginOtpMail;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LoginOtpIssuer;
use App\Services\TwoFactorPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Two',
            'last_name'            => 'Factor',
            'username'             => 'tf_' . $uid,
            'email'                => 'tf_' . $uid . '@airking.com',
            'recovery_email'       => 'tf_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 3, // branch_manager -- a non-admin
            'status_id'            => 1,
        ], $overrides));
    }

    private function makeAdmin(array $overrides = []): User
    {
        return $this->makeUser(array_merge(['role_id' => 1], $overrides));
    }

    public function test_is_admin_distinguishes_the_admin_role(): void
    {
        $this->assertTrue($this->makeAdmin()->isAdmin());
        $this->assertFalse($this->makeUser()->isAdmin());
    }

    /**
     * The central claim of this feature: an admin is not subject to the global
     * switch. If this ever passes for the wrong reason the whole change is
     * pointless, so it is asserted directly against the policy rather than
     * only through HTTP.
     */
    public function test_an_admin_needs_a_code_even_when_the_toggle_is_off(): void
    {
        SystemSetting::setTwoFactorAuthEnabled(false);

        $this->assertTrue(app(TwoFactorPolicy::class)->requiresOtp($this->makeAdmin()));
    }

    public function test_a_non_admin_follows_the_toggle(): void
    {
        $user = $this->makeUser();
        $policy = app(TwoFactorPolicy::class);

        SystemSetting::setTwoFactorAuthEnabled(false);
        $this->assertFalse($policy->requiresOtp($user));

        SystemSetting::setTwoFactorAuthEnabled(true);
        $this->assertTrue($policy->requiresOtp($user));
    }

    public function test_an_account_with_an_unresolvable_role_is_challenged(): void
    {
        SystemSetting::setTwoFactorAuthEnabled(false);

        // The FK on users.role_id makes this unreachable via the database, so
        // build the state directly. The point is that the gate fails closed if
        // that guarantee ever weakens.
        $user = new User(['email' => 'orphan@airking.com']);
        $user->setRelation('role', null);

        $this->assertTrue(app(TwoFactorPolicy::class)->requiresOtp($user));
    }

    public function test_requires_recovery_email_only_when_a_code_is_needed_and_none_is_set(): void
    {
        SystemSetting::setTwoFactorAuthEnabled(false);
        $policy = app(TwoFactorPolicy::class);

        // Admin, no address -> must supply one.
        $this->assertTrue($policy->requiresRecoveryEmail($this->makeAdmin(['recovery_email' => null])));

        // Admin with an address -> nothing to collect.
        $this->assertFalse($policy->requiresRecoveryEmail($this->makeAdmin()));

        // Non-admin with the toggle off needs no code, so no address either --
        // this must not gate users who are not being challenged.
        $this->assertFalse($policy->requiresRecoveryEmail($this->makeUser(['recovery_email' => null])));
    }

    public function test_the_code_goes_to_the_recovery_address_not_the_login_address(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();

        $this->assertTrue(app(LoginOtpIssuer::class)->issue($user));

        Mail::assertSent(LoginOtpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->recovery_email) && ! $mail->hasTo($user->email);
        });
    }

    /**
     * The stored value must not be the code. A database read alone should not
     * be enough to complete a login someone has stolen a password for -- which
     * is exactly what plaintext storage allowed.
     */
    public function test_the_code_is_stored_hashed_and_still_verifies(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();

        app(LoginOtpIssuer::class)->issue($user);

        $mailed = null;
        Mail::assertSent(LoginOtpMail::class, function ($mail) use (&$mailed) {
            $mailed = $mail->otp;
            return true;
        });

        $stored = $user->fresh()->otp_code;

        $this->assertNotNull($mailed);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $mailed);
        $this->assertNotSame($mailed, $stored);
        $this->assertTrue(Hash::check($mailed, $stored));
    }

    public function test_the_code_expires_ten_minutes_out_and_reads_back_as_a_date(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();

        // Pinned against a frozen clock: the TTL is a security parameter, and an
        // isFuture() check alone would pass just as happily for one second or
        // ten hours.
        \Illuminate\Support\Carbon::setTestNow('2026-08-24 12:00:00');

        try {
            app(LoginOtpIssuer::class)->issue($user);

            // The cast matters: verifyOtp compares this in PHP, not SQL.
            $expiry = $user->fresh()->otp_expires_at;
            $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $expiry);
            $this->assertSame('2026-08-24 12:10:00', $expiry->toDateTimeString());
        } finally {
            // In a finally so a failed assertion cannot leak a frozen clock into
            // every test that runs after this one.
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_the_mail_names_the_account_and_the_lifetime_of_the_code(): void
    {
        $user = $this->makeAdmin();

        // The code now lands in a PERSONAL inbox that may receive codes for more
        // than one system. A bare six digits does not tell the reader what they
        // are approving. The TTL is rendered from the value the issuer used, so
        // shortening it cannot leave the text promising the old lifetime.
        $rendered = (new LoginOtpMail('123456', $user, 10))->render();

        $this->assertStringContainsString('123456', $rendered);
        $this->assertStringContainsString($user->email, $rendered);
        $this->assertStringContainsString('10 minutes', $rendered);
    }

    /**
     * Currently true by construction -- one column, overwritten. Asserted anyway
     * because a plausible future change ("don't reissue while one is pending")
     * would break it silently and leave two codes live at once.
     */
    public function test_issuing_a_new_code_invalidates_the_previous_one(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();
        $issuer = app(LoginOtpIssuer::class);

        $codes = [];
        $capture = function ($mail) use (&$codes) {
            $codes[] = $mail->otp;
            return true;
        };

        $issuer->issue($user);
        $issuer->issue($user);

        Mail::assertSent(LoginOtpMail::class, $capture);

        $this->assertCount(2, $codes);
        [$first, $second] = $codes;
        // Two independent draws from 900k values collide about once in 900k
        // runs, which would fail the assertions below for a reason that has
        // nothing to do with invalidation. Cheap to rule out.
        $this->assertNotSame($first, $second, 'Two draws collided; re-run.');

        $stored = $user->fresh()->otp_code;
        $this->assertFalse(Hash::check($first, $stored));
        $this->assertTrue(Hash::check($second, $stored));
    }

    public function test_issue_returns_false_when_the_mail_cannot_be_sent(): void
    {
        $user = $this->makeAdmin();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->assertFalse(app(LoginOtpIssuer::class)->issue($user));
    }

    public function test_the_hashed_code_is_never_serialised_to_a_client(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $fresh = $user->fresh();
        $json = json_encode($fresh->toArray());

        $this->assertStringNotContainsString('otp_code', $json);
        // The key name alone is not enough: an accessor or an API resource could
        // expose the same hash under some other label.
        $this->assertStringNotContainsString($fresh->getRawOriginal('otp_code'), $json);
    }

    public function test_the_mailed_code_exchanges_for_a_token(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $mailed = null;
        Mail::assertSent(LoginOtpMail::class, function ($mail) use (&$mailed) {
            $mailed = $mail->otp;
            return true;
        });

        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => $mailed])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['token']);

        // Single use: the code is cleared once spent.
        $this->assertNull($user->fresh()->otp_code);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '000000'])
            ->assertStatus(422);

        // The real code survives a single wrong guess -- a typo must not burn it.
        $this->assertNotNull($user->fresh()->otp_code);
    }

    public function test_an_expired_code_is_refused(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $mailed = null;
        Mail::assertSent(LoginOtpMail::class, function ($mail) use (&$mailed) {
            $mailed = $mail->otp;
            return true;
        });

        $user->fill(['otp_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => $mailed])
            ->assertStatus(422);
    }

    public function test_verify_is_refused_for_a_user_with_no_pending_code(): void
    {
        $user = $this->makeAdmin();

        // otp_code is null. A null hash must never be treated as a match.
        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '123456'])
            ->assertStatus(422);
    }
}
