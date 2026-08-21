<?php

namespace Tests\Feature\Auth;

use App\Models\AuditTrail;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class FailedLoginAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test drives the real login endpoint; stale throttle counters from
        // a prior test would otherwise divert it into the lockout branch.
        RateLimiter::clear('');
    }

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Fail',
            'last_name'            => 'Login',
            'username'             => 'fail_' . $uid,
            'email'                => 'fail_' . $uid . '@airking.com',
            'recovery_email'       => 'fail_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    /** Solve the stateless math CAPTCHA the login endpoint requires. */
    private function captcha(): array
    {
        $c = $this->getJson('/api/login/challenge')->assertStatus(200)->json('data');

        return [
            'captcha_token'  => $c['captcha_token'],
            'captcha_answer' => (string) ($c['operand_a'] + $c['operand_b']),
        ];
    }

    private function attemptLogin(string $email, string $password)
    {
        return $this->postJson('/api/login', array_merge([
            'email'    => $email,
            'password' => $password,
        ], $this->captcha()));
    }

    /** A valid challenge token answered incorrectly. */
    private function attemptLoginFailingCaptcha(string $email, string $password)
    {
        $c = $this->captcha();
        $c['captcha_answer'] = (string) ((int) $c['captcha_answer'] + 1);

        return $this->postJson('/api/login', array_merge([
            'email'    => $email,
            'password' => $password,
        ], $c));
    }

    public function test_a_wrong_password_on_a_real_account_is_audited(): void
    {
        $user = $this->makeUser();

        $this->attemptLogin($user->email, 'WrongPassword123!')->assertStatus(422);

        $row = AuditTrail::where('action', 'login_failed')
            ->where('user_id', $user->user_id)
            ->orderByDesc('audit_id')
            ->first();

        $this->assertNotNull($row, 'a failed login on a known account must reach the audit trail');
        $this->assertSame('security', $row->table_name);
        $this->assertSame($user->email, $row->new_values['account']);
        $this->assertSame('Invalid credentials.', $row->new_values['reason']);
        $this->assertNotNull($row->ip_address);
    }

    public function test_the_attempted_password_is_never_stored(): void
    {
        $user = $this->makeUser();

        $this->attemptLogin($user->email, 'SuperSecretTypo123!')->assertStatus(422);

        $row = AuditTrail::where('action', 'login_failed')
            ->where('user_id', $user->user_id)
            ->orderByDesc('audit_id')
            ->first();

        $this->assertStringNotContainsString(
            'SuperSecretTypo123!',
            json_encode([$row->old_values, $row->new_values])
        );
    }

    public function test_an_inactive_account_records_its_own_reason(): void
    {
        $user = $this->makeUser(['status_id' => 2]);

        $this->attemptLogin($user->email, 'Secret123!')->assertStatus(422);

        $row = AuditTrail::where('action', 'login_failed')
            ->where('user_id', $user->user_id)
            ->orderByDesc('audit_id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Inactive account.', $row->new_values['reason']);
    }

    public function test_an_unrecognized_email_stays_anonymous(): void
    {
        $stranger = 'nobody_' . uniqid() . '@airking.com';

        $this->attemptLogin($stranger, 'WhateverPass123!')->assertStatus(422);

        // Unrecognized accounts keep the existing treatment: flagged, but the
        // attempted address is deliberately not retained.
        $row = AuditTrail::where('action', 'unauthorized_access')
            ->orderByDesc('audit_id')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
        $this->assertSame('unknown', $row->new_values['attempted_email']);
        $this->assertStringNotContainsString($stranger, json_encode($row->new_values));

        // And nothing was filed as a failure against a real account.
        $this->assertSame(
            0,
            AuditTrail::where('action', 'login_failed')
                ->whereJsonContains('new_values->account', $stranger)
                ->count()
        );
    }

    public function test_a_real_account_failing_the_captcha_is_attributed_to_it(): void
    {
        $user = $this->makeUser();

        $this->attemptLoginFailingCaptcha($user->email, 'Secret123!')->assertStatus(422);

        $row = AuditTrail::where('action', 'login_failed')
            ->where('user_id', $user->user_id)
            ->orderByDesc('audit_id')
            ->first();

        $this->assertNotNull($row, 'a bot-check failure on a known account must be attributed to it');
        $this->assertSame('Invalid CAPTCHA.', $row->new_values['reason']);
        $this->assertSame($user->email, $row->new_values['account']);
    }

    public function test_an_unknown_email_failing_the_captcha_stays_anonymous(): void
    {
        $stranger = 'nobody_' . uniqid() . '@airking.com';

        $this->attemptLoginFailingCaptcha($stranger, 'WhateverPass123!')->assertStatus(422);

        $row = AuditTrail::where('action', 'unauthorized_access')
            ->orderByDesc('audit_id')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
        $this->assertStringNotContainsString($stranger, json_encode($row->new_values));
    }

    public function test_a_successful_login_writes_no_failure_row(): void
    {
        $user = $this->makeUser();

        $before = AuditTrail::where('action', 'login_failed')->count();

        $this->attemptLogin($user->email, 'Secret123!')->assertStatus(200);

        $this->assertSame($before, AuditTrail::where('action', 'login_failed')->count());
    }

    public function test_login_activity_is_still_recorded_alongside_the_audit_row(): void
    {
        $user = $this->makeUser();

        $this->attemptLogin($user->email, 'WrongPassword123!')->assertStatus(422);

        // The audit trail is an addition, not a replacement: the existing
        // login_activities feed still drives the login-history reports.
        $this->assertSame(
            1,
            LoginActivity::where('user_id', $user->user_id)->where('success', false)->count()
        );
    }
}
