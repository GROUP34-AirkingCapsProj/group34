<?php

namespace Tests\Feature\Users;

use App\Mail\AccountCredentialsMail;
use App\Models\AuditTrail;
use App\Models\User;
use App\Services\PasswordAuditLogger;
use App\Services\TemporaryCredentialIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class CredentialIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The generated password must satisfy the same rule the app enforces on
     * user-chosen passwords. Asserting against the real validator rather than a
     * hand-written regex means the generator cannot silently drift away from the
     * policy if the policy is later tightened.
     */
    public function test_generated_password_satisfies_the_application_password_rule(): void
    {
        $issuer = app(TemporaryCredentialIssuer::class);

        // 50 draws: a generator that only occasionally omits a character class
        // would pass a single-draw test by luck.
        for ($i = 0; $i < 50; $i++) {
            $password = $issuer->generateForTesting();

            $this->assertSame(16, strlen($password));
            $this->assertMatchesRegularExpression('/^[^lIO01]+$/', $password);

            $validator = Validator::make(
                ['password' => $password],
                ['password' => [Password::min(8)->mixedCase()->numbers()->symbols()]]
            );

            $this->assertFalse(
                $validator->fails(),
                'Generated password failed the policy: ' . $password
            );
        }
    }

    public function test_generated_passwords_are_not_repeated(): void
    {
        $issuer = app(TemporaryCredentialIssuer::class);

        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[] = $issuer->generateForTesting();
        }

        $this->assertCount(50, array_unique($seen));
    }

    /**
     * Renders the real Blade view. A template that references an undefined
     * variable throws at render time, not at send time -- so a mailable that
     * "sends fine" under Mail::fake() can still explode in production. This is
     * the test that catches that.
     */
    public function test_mail_renders_the_login_email_username_and_password(): void
    {
        $user = new User([
            'first_name' => 'New',
            'last_name'  => 'Hire',
            'username'   => 'new_hire',
            'email'      => 'new_hire@airking.com',
        ]);

        $rendered = (new AccountCredentialsMail($user, 'Tmp!Pass9xYz'))->render();

        $this->assertStringContainsString('new_hire@airking.com', $rendered);
        $this->assertStringContainsString('new_hire', $rendered);
        $this->assertStringContainsString('Tmp!Pass9xYz', $rendered);
        $this->assertStringContainsString('New', $rendered);
    }

    /**
     * The generator's symbol alphabet includes `&`, so a real temporary password
     * can contain HTML-special characters. Asserting on the ESCAPED form is what
     * makes a future switch to {!! !!} -- an injection hole -- fail the suite.
     * Mail clients decode the entity, so the user still sees the raw character.
     */
    public function test_mail_escapes_html_special_characters_in_the_password(): void
    {
        $user = new User([
            'first_name' => 'New',
            'last_name'  => 'Hire',
            'username'   => 'new_hire',
            'email'      => 'new_hire@airking.com',
        ]);

        $rendered = (new AccountCredentialsMail($user, 'a&b<c>d!9Z'))->render();

        $this->assertStringContainsString('a&amp;b&lt;c&gt;d!9Z', $rendered);
        $this->assertStringNotContainsString('a&b<c>d!9Z', $rendered);
    }

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'New',
            'last_name'            => 'Hire',
            'username'             => 'hire_' . $uid,
            'email'                => 'hire_' . $uid . '@airking.com',
            'recovery_email'       => 'hire_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => true,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    public function test_issue_mails_the_recovery_address_not_the_login_address(): void
    {
        Mail::fake();
        $user  = $this->makeUser();
        $actor = $this->makeUser();

        $sent = app(TemporaryCredentialIssuer::class)->issue($user, $actor);

        $this->assertTrue($sent);
        Mail::assertSent(AccountCredentialsMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->recovery_email)
                && ! $mail->hasTo($user->email);
        });
    }

    public function test_issue_replaces_the_hash_with_the_emailed_password(): void
    {
        Mail::fake();
        $user  = $this->makeUser();
        $actor = $this->makeUser();

        app(TemporaryCredentialIssuer::class)->issue($user, $actor);

        $emailed = null;
        Mail::assertSent(AccountCredentialsMail::class, function ($mail) use (&$emailed) {
            $emailed = $mail->plainPassword;
            return true;
        });

        $this->assertNotNull($emailed);
        // The credential that was mailed is the credential that works.
        $this->assertTrue(Hash::check($emailed, $user->fresh()->password_hash));
        $this->assertFalse(Hash::check('Secret123!', $user->fresh()->password_hash));
    }

    public function test_issue_arms_the_must_change_password_gate(): void
    {
        Mail::fake();
        $user  = $this->makeUser(['must_change_password' => false]);
        $actor = $this->makeUser();

        app(TemporaryCredentialIssuer::class)->issue($user, $actor);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_issue_returns_false_and_keeps_the_rotation_when_mail_fails(): void
    {
        $user  = $this->makeUser();
        $actor = $this->makeUser();

        // Fail at send() rather than at to(), the way a dead SMTP host would:
        // the mailable is fully built and handed over before the transport dies.
        // Injecting here also lets us capture the plaintext that was about to go
        // out, which is what the log assertion below needs.
        $emailed = null;
        $pendingMail = \Mockery::mock();
        $pendingMail->shouldReceive('send')
            ->once()
            ->andReturnUsing(function ($mailable) use (&$emailed) {
                $emailed = $mailable->plainPassword;
                throw new \RuntimeException('smtp down');
            });
        Mail::shouldReceive('to')->once()->with($user->recovery_email)->andReturn($pendingMail);

        Log::spy();

        $sent = app(TemporaryCredentialIssuer::class)->issue($user, $actor);

        $this->assertFalse($sent);
        // The hash was already rotated before the send -- it has to be, for the
        // emailed password to work. Resend is what clears this state.
        $this->assertFalse(Hash::check('Secret123!', $user->fresh()->password_hash));

        // The audit is written outside the try/catch on purpose. An issued but
        // undelivered credential is the case an operator most needs a record of,
        // so a refactor that only audits successful sends must fail here.
        $entry = AuditTrail::where('table_name', 'users')
            ->where('record_id', $user->user_id)
            ->where('action', PasswordAuditLogger::ADMIN_CHANGE)
            ->latest('audit_id')
            ->first();

        $this->assertNotNull($entry, 'A failed send must still be audited.');
        $this->assertFalse($entry->new_values['delivered']);

        // The failure log must never carry the credential: it lands in a file far
        // more people can read than can read the user's inbox. Asserting the exact
        // key set is what keeps a future "add more context" edit from quietly
        // putting the plaintext there.
        $this->assertNotNull($emailed);
        $logged = null;
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context) use (&$logged) {
                $logged = $context;

                return $message === 'credential_mail_failed';
            });

        $this->assertSame(['user_id', 'error', 'exception'], array_keys($logged));
        $this->assertStringNotContainsString($emailed, json_encode($logged));
        $this->assertSame(\RuntimeException::class, $logged['exception']);
    }

    public function test_issue_audits_without_recording_the_password(): void
    {
        Mail::fake();
        $user  = $this->makeUser();
        $actor = $this->makeUser();

        app(TemporaryCredentialIssuer::class)->issue($user, $actor);

        $emailed = null;
        Mail::assertSent(AccountCredentialsMail::class, function ($mail) use (&$emailed) {
            $emailed = $mail->plainPassword;
            return true;
        });

        $entry = AuditTrail::where('table_name', 'users')
            ->where('record_id', $user->user_id)
            ->where('action', PasswordAuditLogger::ADMIN_CHANGE)
            ->latest('audit_id')
            ->first();

        $this->assertNotNull($emailed);
        $this->assertNotNull($entry);
        $this->assertSame($actor->user_id, $entry->user_id);
        $this->assertStringContainsString('credentials_emailed', json_encode($entry->new_values));
        $this->assertStringNotContainsString($emailed, json_encode($entry->new_values));
    }

    private function makeAdmin(): User
    {
        return $this->makeUser(['must_change_password' => false]);
    }

    private function createPayload(array $overrides = []): array
    {
        $uid = uniqid();

        return array_merge([
            'first_name'     => 'New',
            'last_name'      => 'Hire',
            'username'       => 'nh_' . $uid,
            'email'          => 'nh_' . $uid . '@airking.com',
            'recovery_email' => 'nh_' . $uid . '@gmail.com',
            'role_id'        => 1,
            'status_id'      => 1,
        ], $overrides);
    }

    public function test_creating_a_user_emails_credentials_and_reports_delivery(): void
    {
        Mail::fake();
        $payload = $this->createPayload();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.credentials_emailed', true);

        $created = User::where('email', $payload['email'])->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->must_change_password);

        Mail::assertSent(AccountCredentialsMail::class, function ($mail) use ($payload) {
            return $mail->hasTo($payload['recovery_email']);
        });
    }

    public function test_the_emailed_password_actually_logs_the_new_user_in(): void
    {
        Mail::fake();
        $payload = $this->createPayload();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(201);

        $emailed = null;
        Mail::assertSent(AccountCredentialsMail::class, function ($mail) use (&$emailed) {
            $emailed = $mail->plainPassword;
            return true;
        });

        // End to end: what landed in the inbox opens the door. The login endpoint
        // also gates on the stateless math CAPTCHA, so solve it the same way the
        // real client does rather than bypassing the endpoint.
        $challenge = $this->getJson('/api/login/challenge')->assertStatus(200)->json('data');

        $this->postJson('/api/login', [
            'email'          => $payload['email'],
            'password'       => $emailed,
            'captcha_token'  => $challenge['captcha_token'],
            'captcha_answer' => (string) ($challenge['operand_a'] + $challenge['operand_b']),
        ])->assertStatus(200);
    }

    public function test_store_ignores_an_admin_supplied_password(): void
    {
        Mail::fake();
        $payload = $this->createPayload(['password' => 'Attacker123!', 'password_confirmation' => 'Attacker123!']);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(201);

        $created = User::where('email', $payload['email'])->first();

        // An admin must not be able to set a password they know: the whole point
        // is that only the account holder ever sees the credential.
        $this->assertFalse(Hash::check('Attacker123!', $created->password_hash));
    }

    public function test_creation_survives_a_mail_failure_and_says_so(): void
    {
        $payload = $this->createPayload();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.credentials_emailed', false);

        // The account is kept: a flaky mail server must not block onboarding.
        $this->assertNotNull(User::where('email', $payload['email'])->first());
    }

    public function test_resend_rotates_the_password_for_a_user_who_never_logged_in(): void
    {
        Mail::fake();
        $user = $this->makeUser(['must_change_password' => true]);
        $before = $user->password_hash;

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users/' . $user->user_id . '/resend-credentials')
            ->assertStatus(200)
            ->assertJsonPath('data.credentials_emailed', true);

        $this->assertNotSame($before, $user->fresh()->password_hash);
        Mail::assertSent(AccountCredentialsMail::class, fn ($mail) => $mail->hasTo($user->recovery_email));
    }

    public function test_resend_is_refused_once_the_user_has_set_their_own_password(): void
    {
        Mail::fake();
        $user = $this->makeUser(['must_change_password' => false]);
        $before = $user->password_hash;

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users/' . $user->user_id . '/resend-credentials')
            ->assertStatus(422);

        // Critical: an admin must not be able to silently blow away a working
        // password. That account uses the forgot-password flow instead.
        $this->assertSame($before, $user->fresh()->password_hash);
        Mail::assertNothingSent();
    }

    public function test_resend_is_refused_for_an_archived_user(): void
    {
        Mail::fake();
        $user = $this->makeUser(['must_change_password' => true]);
        $user->delete();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users/' . $user->user_id . '/resend-credentials')
            ->assertStatus(404);

        Mail::assertNothingSent();
    }

    public function test_resend_reports_a_failed_send(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);
        $admin = $this->makeAdmin();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/' . $user->user_id . '/resend-credentials')
            ->assertStatus(200)
            ->assertJsonPath('data.credentials_emailed', false);
    }
}
