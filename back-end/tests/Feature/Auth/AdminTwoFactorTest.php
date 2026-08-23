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

    /**
     * Five wrong guesses burn the code. This is the defence that does not care
     * how many IP addresses the attacker has: a 6-digit code in a 900,000
     * keyspace is guessable inside its 10-minute window from a cheap proxy
     * pool, and per-IP throttling cannot stop that. Capping attempts per issued
     * code can.
     */
    public function test_five_wrong_guesses_burn_the_code(): void
    {
        // The attempt counter is the subject here, and it lives in the database.
        // The rate limiter would answer 429 before the fifth guess reached the
        // controller, so the test would pass without the counter existing.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $mailed = null;
        Mail::assertSent(LoginOtpMail::class, function ($mail) use (&$mailed) {
            $mailed = $mail->otp;
            return true;
        });

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '000000'])
                ->assertStatus(422);
        }

        $this->assertNull($user->fresh()->otp_code, 'The code should be burned after 5 failures.');

        // Even the REAL code no longer works -- the attacker cannot outlast the
        // counter by eventually guessing right.
        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => $mailed])
            ->assertStatus(422);
    }

    public function test_a_successful_verification_resets_the_attempt_counter(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $mailed = null;
        Mail::assertSent(LoginOtpMail::class, function ($mail) use (&$mailed) {
            $mailed = $mail->otp;
            return true;
        });

        // Two wrong, then right -- a fumbling honest user must not be punished.
        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '000000'])->assertStatus(422);
        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '111111'])->assertStatus(422);

        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => $mailed])
            ->assertStatus(200);

        $this->assertSame(0, (int) $user->fresh()->otp_attempts);
    }

    public function test_issuing_a_fresh_code_clears_previous_failures(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        $this->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '000000'])->assertStatus(422);
        $this->assertSame(1, (int) $user->fresh()->otp_attempts);

        // A new code is a new budget, otherwise a user who fumbled once is
        // permanently closer to being locked out.
        app(LoginOtpIssuer::class)->issue($user->fresh());

        $this->assertSame(0, (int) $user->fresh()->otp_attempts);
    }

    public function test_the_account_keyed_limiter_caps_guesses_from_rotating_addresses(): void
    {
        Mail::fake();
        $user = $this->makeAdmin();
        app(LoginOtpIssuer::class)->issue($user);

        // Every request from a DIFFERENT source address. Per-IP throttling would
        // let all of these through; the account-keyed limit is what stops them.
        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.' . $i])
                ->postJson('/api/verify-otp', ['email' => $user->email, 'otp' => '000000'])
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Rotating IPs must not bypass the cap.');
    }

    /**
     * POST /api/login gates on a stateless math CAPTCHA. Solve it the way the
     * real client does rather than bypassing the endpoint, so these tests
     * exercise the path a user actually takes.
     */
    private function login(User $user, string $password = 'Secret123!'): \Illuminate\Testing\TestResponse
    {
        $challenge = $this->getJson('/api/login/challenge')->assertStatus(200)->json('data');

        return $this->postJson('/api/login', [
            'email'          => $user->email,
            'password'       => $password,
            'captcha_token'  => $challenge['captcha_token'],
            'captcha_answer' => (string) ($challenge['operand_a'] + $challenge['operand_b']),
        ]);
    }

    /**
     * The whole point of the feature: the admin UI can switch two-factor off,
     * and before this the account with the most to lose could therefore disable
     * its own protection.
     */
    public function test_an_admin_is_challenged_even_with_the_toggle_off(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin();

        $this->login($admin)
            ->assertStatus(200)
            ->assertJsonPath('requires_otp', true)
            ->assertJsonMissing(['token']);

        Mail::assertSent(LoginOtpMail::class, fn ($m) => $m->hasTo($admin->recovery_email));
    }

    public function test_a_non_admin_is_not_challenged_with_the_toggle_off(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);

        $this->login($this->makeUser())
            ->assertStatus(200)
            ->assertJsonPath('requires_otp', false)
            ->assertJsonStructure(['token']);

        Mail::assertNothingSent();
    }

    public function test_a_non_admin_is_challenged_with_the_toggle_on(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(true);

        $this->login($this->makeUser())
            ->assertStatus(200)
            ->assertJsonPath('requires_otp', true);

        Mail::assertSent(LoginOtpMail::class);
    }

    public function test_a_user_with_no_recovery_address_is_asked_for_one(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin(['recovery_email' => null]);

        $this->login($admin)
            ->assertStatus(200)
            ->assertJsonPath('requires_recovery_email', true)
            ->assertJsonMissing(['token']);

        // Nothing written and nothing sent: this is a request for information,
        // not a failed challenge.
        Mail::assertNothingSent();
        $this->assertNull($admin->fresh()->otp_code);
    }

    /**
     * Fails closed. An authentication control that can be bypassed by breaking
     * mail delivery is not a control.
     */
    public function test_login_is_refused_when_the_code_cannot_be_sent(): void
    {
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->login($admin)
            ->assertStatus(503)
            ->assertJsonMissing(['token']);
    }

    /**
     * Without this, an attacker holding a stolen admin password could trigger
     * codes indefinitely and leave no trace anywhere in the system except an
     * email in the victim's inbox -- the highest-signal event in an
     * account-takeover investigation, unrecorded.
     */
    public function test_issuing_a_code_is_recorded_as_a_login_attempt(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin();

        $this->login($admin)->assertStatus(200);

        $row = \App\Models\LoginActivity::where('user_id', $admin->user_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'Triggering a login code must leave a trace.');
        $this->assertStringContainsString('code', strtolower((string) $row->failure_reason));

        $this->assertDatabaseHas('audit_trail', [
            'user_id' => $admin->user_id,
            'action'  => 'login_code_issued',
        ]);
    }

    /**
     * Issuing a code is not a failed login. Recording it as one would stamp a
     * bogus failure on the audit trail for every successful admin sign-in,
     * drowning the signal an admin reviewing "who has been failing to get in"
     * actually needs.
     */
    public function test_issuing_a_code_is_not_recorded_as_a_failed_login(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin();

        $before = \App\Models\AuditTrail::where('action', 'login_failed')->count();

        $this->login($admin)->assertStatus(200);

        $this->assertSame(
            $before,
            \App\Models\AuditTrail::where('action', 'login_failed')->count(),
            'A 2FA challenge must not appear as a failed login.'
        );
    }

    /**
     * The rate limiter writes to the cache, which DatabaseTransactions does not
     * roll back. Under the array store each test gets a fresh application and so
     * a fresh store, but this suite must not depend on the cache driver a runner
     * happens to configure -- with a file or redis store the limiter test would
     * otherwise leave 429s waiting for whatever runs next.
     */
    protected function tearDown(): void
    {
        $this->app?->make('cache')->store()->flush();

        parent::tearDown();
    }
}
