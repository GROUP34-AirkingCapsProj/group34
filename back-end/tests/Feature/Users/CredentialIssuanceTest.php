<?php

namespace Tests\Feature\Users;

use App\Mail\AccountCredentialsMail;
use App\Models\User;
use App\Services\TemporaryCredentialIssuer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class CredentialIssuanceTest extends TestCase
{
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
}
