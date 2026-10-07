<?php

namespace Tests\Feature\Auth;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * The bot check has to have a ceiling of its own.
 *
 * It runs BEFORE the password is ever compared, so a client that never solves it
 * never reaches the credentials limiter — the one gate that locks the front door
 * stays at zero attempts no matter how long the hammering goes on. Without a cap
 * here, the only thing standing between an automated CAPTCHA solver and an
 * unlimited number of tries is the route's per-minute throttle, which decays
 * every sixty seconds and so caps nothing over the span of an attack.
 */
class CaptchaLockoutTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The route's throttle:12,1 is a separate, per-minute defence. Left on,
        // its budget runs out mid-test and every assertion below would be
        // measuring that middleware instead of the controller's CAPTCHA bucket.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Captcha',
            'last_name'            => 'Lockout',
            'username'             => 'cap_' . $uid,
            'email'                => 'cap_' . $uid . '@airking.com',
            'recovery_email'       => 'cap_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

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

    private function failCaptchaUpToTheCap(User $user): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->attemptLoginFailingCaptcha($user->email, 'Secret123!')->assertStatus(422);
        }
    }

    public function test_repeated_captcha_failures_lock_further_login_attempts(): void
    {
        $user = $this->makeUser();

        $this->failCaptchaUpToTheCap($user);

        // Correct credentials AND a correctly solved challenge. The lockout has
        // to hold anyway: the point is that the gate is consulted at all.
        $response = $this->attemptLogin($user->email, 'Secret123!');

        $response->assertStatus(422);
        $this->assertArrayHasKey('captcha_answer', $response->json('errors'));
        $this->assertStringContainsString(
            'Too many',
            $response->json('errors.captcha_answer.0'),
        );
        $this->assertNull($response->json('token'));
    }

    public function test_the_captcha_lockout_is_audited_against_the_account(): void
    {
        $user = $this->makeUser();

        $this->failCaptchaUpToTheCap($user);
        $this->attemptLogin($user->email, 'Secret123!')->assertStatus(422);

        $row = AuditTrail::where('action', 'login_failed')
            ->where('user_id', $user->user_id)
            ->orderByDesc('audit_id')
            ->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('security check', strtolower($row->new_values['reason']));
    }

    public function test_failures_below_the_cap_still_let_a_real_login_through(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 9; $i++) {
            $this->attemptLoginFailingCaptcha($user->email, 'Secret123!')->assertStatus(422);
        }

        // A person fumbling the arithmetic a few times must not be shut out of a
        // password they know.
        $this->attemptLogin($user->email, 'Secret123!')->assertStatus(200);
    }

    public function test_a_successful_login_clears_the_captcha_bucket(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 9; $i++) {
            $this->attemptLoginFailingCaptcha($user->email, 'Secret123!')->assertStatus(422);
        }

        $this->attemptLogin($user->email, 'Secret123!')->assertStatus(200);

        // Nine more would cross the cap if the counter had survived the sign-in.
        for ($i = 0; $i < 9; $i++) {
            $this->attemptLoginFailingCaptcha($user->email, 'Secret123!')->assertStatus(422);
        }

        $this->attemptLogin($user->email, 'Secret123!')->assertStatus(200);
    }

    public function test_the_lockout_is_keyed_per_account_not_globally(): void
    {
        $victim = $this->makeUser();
        $bystander = $this->makeUser();

        $this->failCaptchaUpToTheCap($victim);

        // One account's bot-check failures must not become a denial of service
        // against everyone else signing in from the same office IP... except they
        // share an IP, so the key has to include the email to tell them apart.
        $this->attemptLogin($bystander->email, 'Secret123!')->assertStatus(200);
    }
}
