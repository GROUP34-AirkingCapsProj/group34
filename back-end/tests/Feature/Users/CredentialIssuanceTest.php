<?php

namespace Tests\Feature\Users;

use App\Mail\AccountCredentialsMail;
use App\Models\AuditTrail;
use App\Models\User;
use App\Services\PasswordAuditLogger;
use App\Services\TemporaryCredentialIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
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
        $user = $this->makeUser();

        $sent = app(TemporaryCredentialIssuer::class)->issue($user);

        $this->assertTrue($sent);
        Mail::assertSent(AccountCredentialsMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->recovery_email)
                && ! $mail->hasTo($user->email);
        });
    }

    public function test_issue_replaces_the_hash_with_the_emailed_password(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        app(TemporaryCredentialIssuer::class)->issue($user);

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
        $user = $this->makeUser(['must_change_password' => false]);

        app(TemporaryCredentialIssuer::class)->issue($user);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_issue_returns_false_and_keeps_the_rotation_when_mail_fails(): void
    {
        $user = $this->makeUser();

        // Force the transport to throw the way a dead SMTP host would.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));

        $sent = app(TemporaryCredentialIssuer::class)->issue($user);

        $this->assertFalse($sent);
        // The hash was already rotated before the send -- it has to be, for the
        // emailed password to work. Resend is what clears this state.
        $this->assertFalse(Hash::check('Secret123!', $user->fresh()->password_hash));
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

        $this->assertNotNull($entry);
        $this->assertSame($actor->user_id, $entry->user_id);
        $this->assertStringContainsString('credentials_emailed', json_encode($entry->new_values));
        $this->assertStringNotContainsString($emailed, json_encode($entry->new_values));
    }
}
