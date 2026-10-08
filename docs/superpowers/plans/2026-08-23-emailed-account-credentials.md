# Emailed Account Credentials Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an admin creates an account, the system generates a strong temporary password and emails it — with the login email — to the user's recovery address, so the user learns their own credentials without the admin ever handling the secret.

**Architecture:** One new service, `TemporaryCredentialIssuer`, owns "give this user a new temporary password and mail it." `UserController::store()` calls it after creating the row; a new `resendCredentials()` endpoint calls the same method. The admin form drops its password fields. The existing `must_change_password` gate (already built) forces the replacement at first login — this plan adds delivery only.

**Tech Stack:** Laravel 11 (PHP 8.2), Sanctum auth, PHPUnit with `DatabaseTransactions`, React 18 SPA (Vite), plain CSS.

**Spec:** `docs/superpowers/specs/2026-08-23-emailed-account-credentials-design.md`

---

## Background an engineer needs before starting

**Run the backend test suite from `back-end/`:**

```bash
cd back-end && php artisan test --filter=SomeTestClass
```

`phpunit.xml` sets `MAIL_MAILER=array`, so mail never leaves the machine during tests. Tests use `DatabaseTransactions` (not `RefreshDatabase`) — they run against a real seeded database and roll back. Role `1` and status `1` exist in that seed data; every existing test relies on this.

**Response envelope.** Controllers use the `App\Traits\ApiResponse` trait. `success($data, $message, $code)` produces `{"success": true, "message": ..., "data": ...}`. So a `data` key named `credentials_emailed` is reached in tests as `data.credentials_emailed`.

**The gate already works.** `must_change_password` defaults to `true` on the `users` table, `App\Http\Middleware\CheckPasswordChange` returns 403 with `code: password_change_required` while it is set, and the SPA has a forced change-password screen. Do not rebuild any of that.

**Audit logging.** `App\Services\PasswordAuditLogger::log($action, $subject, $context, $actor)` writes to `audit_trail` and swallows its own failures. Use the existing `ADMIN_CHANGE` constant. Never put a plaintext password in the context array.

**Existing mail pattern to copy:** `app/Mail/PasswordResetOtpMail.php` and `resources/views/emails/password_reset_otp.blade.php`, sent with `Mail::to($address)->send(new SomeMailable(...))` in `ForgotPasswordOtpController`. `QUEUE_CONNECTION=sync`; do not queue.

---

## File Structure

**Backend — create:**
- `back-end/app/Services/TemporaryCredentialIssuer.php` — generates the password, saves the hash, sends the mail, audits. The only place that knows how a temporary credential is made.

> **Amended during execution (Task 3 review):** `issue()`'s signature is
> `issue(User $user, User $actor): bool` — the actor is **required**, not
> `?User $actor = null` as originally written below. Both call sites (Tasks 4
> and 5) already pass `$request->user()`, so nothing downstream changes. See the
> spec for the reasoning. Also: `issue()` must never be called inside a caller's
> database transaction — a rollback would revoke a password already sitting in
> the user's inbox.
- `back-end/app/Mail/AccountCredentialsMail.php` — the Mailable.
- `back-end/resources/views/emails/account_credentials.blade.php` — the message body.
- `back-end/tests/Feature/Users/CredentialIssuanceTest.php` — the whole feature's tests.

**Backend — modify:**
- `back-end/app/Http/Controllers/Api/UserController.php` — `store()` drops password handling and calls the issuer; new `resendCredentials()`.
- `back-end/routes/api.php` — one route line.
- `back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php` — its `payload()` helper sends a `password`, which `store()` will no longer accept.

**Frontend — modify:**
- `frontend/src/admin/services/api.js` — add `usersAPI.resendCredentials`.
- `frontend/src/admin/components/UserManagementPanel.jsx` — remove create-mode password fields, add the Resend button, surface the delivery warning.
- `frontend/src/admin/styles/user_management.css` — one button style.

---

### Task 1: The password generator

The generator is the riskiest single piece: if it can emit a password that fails the app's own `Password` rule, a user gets a credential they cannot use. Build and test it in isolation before anything depends on it.

**Files:**
- Create: `back-end/app/Services/TemporaryCredentialIssuer.php`
- Test: `back-end/tests/Feature/Users/CredentialIssuanceTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Users/CredentialIssuanceTest.php`:

```php
<?php

namespace Tests\Feature\Users;

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
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: FAIL — `Class "App\Services\TemporaryCredentialIssuer" does not exist`.

- [ ] **Step 3: Write the generator**

Create `back-end/app/Services/TemporaryCredentialIssuer.php`:

```php
<?php

namespace App\Services;

/**
 * Issues the one-time password a newly created account logs in with.
 *
 * The plaintext exists only as a local variable and in the outgoing message.
 * It is never returned to the admin, never stored, and never logged -- the
 * `must_change_password` gate makes it single-use in practice, and nobody but
 * the account holder should ever be able to read it.
 */
class TemporaryCredentialIssuer
{
    private const LENGTH = 16;

    private const LOWER   = 'abcdefghijkmnopqrstuvwxyz';
    private const UPPER   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const DIGITS  = '23456789';
    private const SYMBOLS = '!@#$%^&*?-_=+';

    /**
     * Test seam. The generator is private because nothing outside this class
     * should mint a password without also persisting and mailing it, but its
     * output must be verifiable against the app's password policy.
     *
     * @internal
     */
    public function generateForTesting(): string
    {
        return $this->generate();
    }

    /**
     * Guarantees one character from each required class by construction, then
     * shuffles, so the result satisfies
     * Password::min(8)->mixedCase()->numbers()->symbols() every time rather
     * than merely usually.
     *
     * Visually ambiguous characters (l, I, 1, O, 0) are excluded from the
     * alphabets: this password gets read off a screen and retyped by hand.
     */
    private function generate(): string
    {
        $pool = self::LOWER . self::UPPER . self::DIGITS . self::SYMBOLS;

        $chars = [
            $this->pick(self::LOWER),
            $this->pick(self::UPPER),
            $this->pick(self::DIGITS),
            $this->pick(self::SYMBOLS),
        ];

        for ($i = count($chars); $i < self::LENGTH; $i++) {
            $chars[] = $this->pick($pool);
        }

        // Fisher-Yates with random_int: str_shuffle() uses the non-cryptographic
        // RNG, which would undermine the whole point of random_int() above.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TemporaryCredentialIssuer.php back-end/tests/Feature/Users/CredentialIssuanceTest.php
git commit -m "feat(users): add a policy-satisfying temporary password generator"
```

---

### Task 2: The mailable and its template

**Files:**
- Create: `back-end/app/Mail/AccountCredentialsMail.php`
- Create: `back-end/resources/views/emails/account_credentials.blade.php`
- Test: `back-end/tests/Feature/Users/CredentialIssuanceTest.php`

- [ ] **Step 1: Write the failing test**

Append this method inside the `CredentialIssuanceTest` class (and add the two `use` lines at the top of the file: `use App\Mail\AccountCredentialsMail;` and `use App\Models\User;`):

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: FAIL — `Class "App\Mail\AccountCredentialsMail" does not exist`.

- [ ] **Step 3: Write the mailable**

Create `back-end/app/Mail/AccountCredentialsMail.php`:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Airking Inventory account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account_credentials',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Write the template**

Create `back-end/resources/views/emails/account_credentials.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Airking Inventory account</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 480px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);">
                    <tr>
                        <td style="padding: 32px 28px 8px;">
                            <p style="margin: 0; font-size: 18px; font-weight: 600; color: #18181b;">
                                Inventory System
                            </p>
                            <p style="margin: 8px 0 0; font-size: 14px; color: #71717a;">
                                Your account is ready
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px 8px;">
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                Hi {{ $user->first_name }}, an administrator has created an
                                account for you. Sign in with the details below.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f5; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <p style="margin: 0 0 4px; font-size: 12px; color: #71717a;">Email</p>
                                        <p style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">{{ $user->email }}</p>

                                        <p style="margin: 0 0 4px; font-size: 12px; color: #71717a;">Username</p>
                                        <p style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">{{ $user->username }}</p>

                                        <p style="margin: 0 0 4px; font-size: 12px; color: #71717a;">Temporary password</p>
                                        <p style="margin: 0; font-size: 18px; font-weight: 700; letter-spacing: 0.05em; color: #18181b; font-family: ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace;">{{ $plainPassword }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 28px;">
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                You will be asked to choose a new password the first time you
                                sign in. Until you do, the rest of the system stays locked.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px 32px;">
                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #a1a1aa;">
                                If you were not expecting this email, contact your administrator.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Mail/AccountCredentialsMail.php back-end/resources/views/emails/account_credentials.blade.php back-end/tests/Feature/Users/CredentialIssuanceTest.php
git commit -m "feat(users): add the account credentials mailable and template"
```

---

### Task 3: `issue()` — persist, send, audit

**Files:**
- Modify: `back-end/app/Services/TemporaryCredentialIssuer.php`
- Test: `back-end/tests/Feature/Users/CredentialIssuanceTest.php`

- [ ] **Step 1: Write the failing tests**

Add these `use` lines to the top of the test file:

```php
use App\Models\AuditTrail;
use App\Services\PasswordAuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
```

Add `use DatabaseTransactions;` as the first line inside the class body, then append these methods:

```php
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
```

> **If `AuditTrail`'s primary key is not `audit_id`:** check `back-end/app/Models/AuditTrail.php` for its `$primaryKey` and use that column in `latest()`. Do not guess.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: FAIL — `Call to undefined method App\Services\TemporaryCredentialIssuer::issue()`.

- [ ] **Step 3: Implement `issue()`**

In `back-end/app/Services/TemporaryCredentialIssuer.php`, add these imports below the namespace:

```php
use App\Mail\AccountCredentialsMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
```

and add this method to the class, above `generateForTesting()`:

```php
    /**
     * Rotate the user's password to a fresh temporary one and mail it to their
     * recovery address.
     *
     * The rotation is committed before the send, by necessity: the hash must be
     * saved for the emailed password to work. So a failed send leaves a valid
     * but undelivered credential -- which is exactly the state the resend
     * endpoint exists to clear. Returns whether the mail actually went out so
     * the caller can tell the admin.
     *
     * @param  User  $user  the account receiving the credential
     * @param  User|null  $actor  the admin performing it; defaults to the user
     */
    public function issue(User $user, ?User $actor = null): bool
    {
        $plain = $this->generate();

        $user->forceFill([
            'password_hash'        => Hash::make($plain),
            'must_change_password' => true,
        ])->save();

        $delivered = true;

        try {
            Mail::to($user->recovery_email)->send(new AccountCredentialsMail($user, $plain));
        } catch (\Throwable $e) {
            $delivered = false;
            // Deliberately does not include $plain: this line goes to a log file
            // that far more people can read than can read the user's inbox.
            Log::warning('credential_mail_failed', [
                'user_id' => $user->user_id,
                'error'   => $e->getMessage(),
            ]);
        }

        app(PasswordAuditLogger::class)->log(
            PasswordAuditLogger::ADMIN_CHANGE,
            $user,
            ['method' => 'credentials_emailed', 'delivered' => $delivered],
            $actor,
        );

        return $delivered;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/TemporaryCredentialIssuer.php back-end/tests/Feature/Users/CredentialIssuanceTest.php
git commit -m "feat(users): issue and mail temporary credentials to the recovery address"
```

---

### Task 4: Wire `store()` to the issuer

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/UserController.php:49-104`
- Modify: `back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php:28-41`
- Test: `back-end/tests/Feature/Users/CredentialIssuanceTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `CredentialIssuanceTest`:

```php
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

        // End to end: what landed in the inbox opens the door.
        $this->postJson('/api/login', [
            'email'    => $payload['email'],
            'password' => $emailed,
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
```

> **If the login route or its field names differ:** confirm with `grep -n "login" back-end/routes/api.php` and check `AuthController::login()` for whether it takes `email` or `username`. Adjust the one call in `test_the_emailed_password_actually_logs_the_new_user_in` to match; do not change the rest.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: FAIL — the create tests return 422 (`password` is still required) and there is no `data.credentials_emailed` key.

- [ ] **Step 3: Modify `store()`**

In `back-end/app/Http/Controllers/Api/UserController.php`, add the import beside the existing ones:

```php
use App\Services\TemporaryCredentialIssuer;
```

Delete this line from the `store()` validation array:

```php
                'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
```

Replace this block:

```php
            // Hash password
            $validated['password_hash'] = Hash::make($validated['password']);
            unset($validated['password']);
            unset($validated['password_confirmation']);

            $locationIds = $validated['location_ids'] ?? null;
            unset($validated['location_ids']);

            $user = User::create($validated);

            // Sync location assignments via user_locations pivot
            if ($locationIds !== null) {
                $user->locations()->sync($locationIds);
            }

            return $this->success(
                $user->load(['role', 'status', 'location', 'locations']),
                'User created successfully',
                201
            );
```

with:

```php
            $locationIds = $validated['location_ids'] ?? null;
            unset($validated['location_ids']);

            // A placeholder only. The row needs a non-null hash to be insertable;
            // the issuer overwrites it a few lines down with the password that is
            // actually mailed. Nobody can authenticate with this value -- it is a
            // hash of random bytes that are immediately discarded.
            $validated['password_hash'] = Hash::make(bin2hex(random_bytes(32)));

            $user = User::create($validated);

            // Sync location assignments via user_locations pivot
            if ($locationIds !== null) {
                $user->locations()->sync($locationIds);
            }

            $emailed = app(TemporaryCredentialIssuer::class)->issue($user, $request->user());

            return $this->success(
                [
                    'user'                => $user->fresh()->load(['role', 'status', 'location', 'locations']),
                    'credentials_emailed' => $emailed,
                ],
                $emailed
                    ? 'User created. Login details have been emailed to their recovery address.'
                    : 'User created, but the credentials email could not be sent. Use Resend to try again.',
                201
            );
```

> **Note the response shape change:** `data` was the serialised user; it is now `{user, credentials_emailed}`. Task 6 updates the one frontend caller. Nothing else reads this response.

- [ ] **Step 4: Fix the existing test helper that sends a password**

In `back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php`, delete these two lines from the `payload()` method (lines 38-39):

```php
            'password'              => 'Secret123!',
            'password_confirmation' => 'Secret123!',
```

Also add `Mail::fake();` as the first line of every test method in that file that posts to `/api/users`, and add `use Illuminate\Support\Facades\Mail;` to its imports — otherwise those tests will attempt real sends through the `array` transport and needlessly exercise the mailer.

- [ ] **Step 5: Run the affected suites**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
cd back-end && php artisan test --filter=RecoveryEmailValidationTest
cd back-end && php artisan test --filter=RbacRouteMatrixTest
```

Expected: all PASS. `RbacRouteMatrixTest` only asserts status codes for `POST /api/users` by role, so it should be unaffected — if it fails, read the failure before changing anything, and fix the matrix expectation only if the change genuinely altered an authorization outcome.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/UserController.php back-end/tests/Feature/Users/CredentialIssuanceTest.php back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php
git commit -m "feat(users): email generated credentials instead of taking an admin password"
```

---

### Task 5: The resend endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/UserController.php`
- Modify: `back-end/routes/api.php:156`
- Test: `back-end/tests/Feature/Users/CredentialIssuanceTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `CredentialIssuanceTest`:

```php
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
```

Note the archived case expects **404**, not 422: `Route::post('/users/{user}/...')` uses implicit model binding, and `User` uses `SoftDeletes`, so a soft-deleted user never resolves and Laravel returns 404 before the controller runs. That is the correct outcome — the explicit guard in the controller is belt-and-braces for any future non-bound call path.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: FAIL — the resend tests return 404 for every case because the route does not exist.

- [ ] **Step 3: Add the controller method**

In `back-end/app/Http/Controllers/Api/UserController.php`, add this method after `store()`:

```php
    /**
     * Re-issue and re-send the temporary credentials for an account that has
     * never completed its first login.
     *
     * Restricted to `must_change_password` accounts on purpose. Resending mints
     * a NEW password and invalidates the old one -- harmless for someone who
     * never received the first email, but for a user who already chose their own
     * password it would silently lock them out. Those accounts use the
     * forgot-password flow, which is what it is for.
     */
    public function resendCredentials(Request $request, User $user)
    {
        try {
            if ($user->trashed()) {
                return $this->error('Cannot issue credentials for an archived account.', 422);
            }

            if (! $user->must_change_password) {
                return $this->error(
                    'This user has already set their own password. Ask them to use "Forgot password" instead.',
                    422
                );
            }

            $emailed = app(TemporaryCredentialIssuer::class)->issue($user, $request->user());

            return $this->success(
                ['credentials_emailed' => $emailed],
                $emailed
                    ? 'New login details have been emailed to their recovery address.'
                    : 'The credentials email could not be sent. Check the mail configuration and try again.',
            );
        } catch (\Exception $e) {
            return $this->error('Failed to resend credentials: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 4: Add the route**

In `back-end/routes/api.php`, inside the same `permission:user_account_management,update` group that contains the `change-password` route (around line 156), add:

```php
        Route::post('/users/{user}/resend-credentials', [UserController::class, 'resendCredentials']);
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=CredentialIssuanceTest
```

Expected: PASS, 16 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/UserController.php back-end/routes/api.php back-end/tests/Feature/Users/CredentialIssuanceTest.php
git commit -m "feat(users): add a guarded resend-credentials endpoint"
```

---

### Task 6: Frontend — drop the password fields, add Resend

No JS test infrastructure exists for this panel, so this task is verified by running the app. Do the API client first so the component has something to call.

**Files:**
- Modify: `frontend/src/admin/services/api.js:1690` (inside `usersAPI`)
- Modify: `frontend/src/admin/components/UserManagementPanel.jsx`
- Modify: `frontend/src/admin/styles/user_management.css:404`

- [ ] **Step 1: Add the API client method**

In `frontend/src/admin/services/api.js`, inside the `usersAPI` object, add after `restore`:

```js
  resendCredentials: async (id) =>
    apiRequest(`/users/${id}/resend-credentials`, { method: 'POST' }),
```

- [ ] **Step 2: Remove password from create-mode form state and validation**

In `frontend/src/admin/components/UserManagementPanel.jsx`, in `validateForm()`, replace this block:

```js
    if (!editingUser) {
      if (!formData.password) errors.password = 'Password is required';
      else if (formData.password.length < 8) errors.password = 'Password must be at least 8 characters';
      if (formData.password !== formData.password_confirmation) errors.password_confirmation = 'Passwords do not match';
    } else {
      if (formData.password && formData.password.length < 8) errors.password = 'Password must be at least 8 characters';
      if (formData.password && formData.password !== formData.password_confirmation) errors.password_confirmation = 'Passwords do not match';
    }
```

with:

```js
    // Create mode has no password field at all -- the backend generates one and
    // emails it. Edit mode keeps the optional admin-set password unchanged.
    if (editingUser) {
      if (formData.password && formData.password.length < 8) errors.password = 'Password must be at least 8 characters';
      if (formData.password && formData.password !== formData.password_confirmation) errors.password_confirmation = 'Passwords do not match';
    }
```

Leave `formData`'s `password` / `password_confirmation` keys in place — edit mode still uses them.

- [ ] **Step 3: Update the create branch of `handleSubmit`**

Replace this block in `handleSubmit`:

```js
        const roleId = Number(formData.role_id);
        const locationId = formData.location_id ? Number(formData.location_id) : null;
        if (!roleId || !formData.password || formData.password.length < 8) {
          setSubmitting(false);
          return;
        }
        const payload = {
          first_name: String(formData.first_name || '').trim(),
          last_name: String(formData.last_name || '').trim(),
          username: String(formData.username || '').trim(),
          email: String(formData.email || '').trim(),
          recovery_email: String(formData.recovery_email || '').trim(),
          password: String(formData.password),
          password_confirmation: String(formData.password_confirmation || formData.password || ''),
          phone: (formData.phone && String(formData.phone).trim()) || null,
          role_id: roleId,
          location_id: locationId,
          status_id: 1,
        };
        await usersAPI.create(payload);
        toast.success('User created successfully and added to the database.');
```

with:

```js
        const roleId = Number(formData.role_id);
        const locationId = formData.location_id ? Number(formData.location_id) : null;
        if (!roleId) {
          setSubmitting(false);
          return;
        }
        const payload = {
          first_name: String(formData.first_name || '').trim(),
          last_name: String(formData.last_name || '').trim(),
          username: String(formData.username || '').trim(),
          email: String(formData.email || '').trim(),
          recovery_email: String(formData.recovery_email || '').trim(),
          phone: (formData.phone && String(formData.phone).trim()) || null,
          role_id: roleId,
          location_id: locationId,
          status_id: 1,
        };
        const res = await usersAPI.create(payload);
        if (res?.data?.credentials_emailed === false) {
          // The account exists and has a working password -- it just did not
          // reach the user. Resend is the recovery, so name it.
          toast.error('User created, but the credentials email failed to send. Use Resend on their row to try again.');
        } else {
          toast.success(`User created. Login details emailed to ${payload.recovery_email}.`);
        }
```

- [ ] **Step 4: Replace the password inputs with an explanatory note**

Replace the password `form-group` (the one containing `um-modal-password`) and the entire `{(formData.password || !editingUser) && (...)}` confirm-password block with:

```jsx
              {editingUser ? (
                <div className="form-group">
                  <label htmlFor="um-modal-password">New Password (leave blank to keep current)</label>
                  <PasswordFieldWithToggle
                    id="um-modal-password"
                    name="password"
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder="Leave blank to keep current"
                    autoComplete="new-password"
                  />
                  {formErrors.password && <span className="um-field-error">{formErrors.password}</span>}
                </div>
              ) : (
                <div className="form-group">
                  <label>Password</label>
                  <p className="um-credentials-note">
                    A temporary password is generated automatically and emailed to
                    the recovery address above. The user must change it the first
                    time they sign in.
                  </p>
                </div>
              )}
            </div>
            {editingUser && formData.password && (
              <div className="form-row">
                <div className="form-group">
                  <label htmlFor="um-modal-password-confirm">Confirm Password</label>
                  <PasswordFieldWithToggle
                    id="um-modal-password-confirm"
                    name="password_confirmation"
                    value={formData.password_confirmation}
                    onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                    placeholder="Re-enter password"
                    autoComplete="new-password"
                  />
                  {formErrors.password_confirmation && <span className="um-field-error">{formErrors.password_confirmation}</span>}
                </div>
                <div className="form-group"></div>
              </div>
            )}
```

- [ ] **Step 5: Add the Resend handler**

Add this state declaration beside the other `useState` calls:

```js
  const [resendingId, setResendingId] = useState(null);
```

and this handler beside `handleDeactivate`:

```js
  const handleResendCredentials = async (user) => {
    const id = user.user_id || user.id;
    const ok = await confirmAction({
      title: 'Resend login details?',
      message: `A new temporary password will be generated and emailed to ${user.recovery_email || 'their recovery address'}. Their current temporary password will stop working.`,
      confirmText: 'Resend',
    });
    if (!ok) return;

    setResendingId(id);
    try {
      const res = await usersAPI.resendCredentials(id);
      if (res?.data?.credentials_emailed === false) {
        toast.error('The email could not be sent. Check the mail configuration and try again.');
      } else {
        toast.success('New login details sent.');
      }
    } catch (err) {
      toast.error(err.message || 'Failed to resend login details.');
    } finally {
      setResendingId(null);
    }
  };
```

> **Check `confirmAction`'s signature first** in `frontend/src/admin/utils/confirm.js` and match how `handleDeactivate` calls it. If it takes a plain string, pass the message string instead of the object.

- [ ] **Step 6: Add the Resend button to the row**

In the `um-actions` div, between the Edit button and the Deactivate button, add:

```jsx
                        <button
                          className="um-btn-resend"
                          onClick={() => handleResendCredentials(user)}
                          disabled={resendingId !== null || deactivatingId !== null || !user.must_change_password}
                          title={user.must_change_password
                            ? 'Email new login details to this user'
                            : 'This user has already set their own password — they should use Forgot password'}
                        >
                          {resendingId === (user.user_id || user.id) ? 'Sending...' : 'Resend'}
                        </button>
```

The `disabled` condition mirrors the server-side guard, so the admin sees why rather than getting a 422.

- [ ] **Step 7: Add the styles**

In `frontend/src/admin/styles/user_management.css`, after the `.um-btn-edit:hover` rule (line 403), add:

```css
.um-btn-resend {
  background: #f5f3ff;
  color: #7c3aed;
  border: 1px solid #ddd6fe;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.um-btn-resend:hover:not(:disabled) {
  background: #ede9fe;
}

.um-btn-resend:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.um-credentials-note {
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: #71717a;
}
```

- [ ] **Step 8: Verify `must_change_password` reaches the frontend**

The Resend button's `disabled` state reads `user.must_change_password`, so the field must survive serialisation. Check it is not in `$hidden`:

```bash
grep -n "hidden" -A 8 back-end/app/Models/User.php
```

If `must_change_password` is hidden, remove it from that array — it is not a secret, and `AuthController` already returns it for the forced-change screen. Then confirm end to end:

```bash
grep -n "must_change_password" back-end/app/Http/Controllers/Api/UserController.php
```

- [ ] **Step 9: Verify in the running app**

Start the app and walk the flow by hand:

1. Open User Management, click Add User. **Confirm there is no password field** — just the note.
2. Create a user with a real recovery address you can read.
3. Confirm the success toast names the recovery address.
4. Check the inbox (or `back-end/storage/logs/laravel.log` if `MAIL_MAILER=log`) for the message with the login email, username, and temporary password.
5. Log in as that user with the emailed password. **Confirm the forced change-password screen appears** and the rest of the app is blocked.
6. Change the password, confirm normal access.
7. Back in User Management as admin, confirm the Resend button on that user is now **disabled** with the explanatory tooltip.
8. Create a second user, and click Resend on them while they are still unactivated. Confirm a second email arrives with a different password, and that the first password no longer works.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/components/UserManagementPanel.jsx frontend/src/admin/styles/user_management.css
git commit -m "feat(users): drop the admin password field and add resend credentials"
```

---

### Task 7: Full suite and documentation

- [ ] **Step 1: Run the whole backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS. If anything unrelated to this feature fails, check whether it also fails on `git stash` — do not "fix" a pre-existing failure inside this change.

- [ ] **Step 2: Search for any other code that sent a password to `POST /users`**

```bash
grep -rn "password_confirmation" back-end/tests frontend/src --include=*.php --include=*.js --include=*.jsx
grep -rn "usersAPI.create" frontend/src
```

Any remaining caller that sends `password` to user creation is now sending a field the backend ignores. Remove those fields. If a call site turns up that this plan did not anticipate, fix it and note it in the commit message.

- [ ] **Step 3: Update the deployment note**

`DEPLOYMENT.md` documents mail configuration for the password-reset flow. Add one line under that section recording that account creation now also sends mail, so an operator knows a broken `MAIL_*` config blocks onboarding rather than only password resets.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "docs: note that account creation now depends on mail delivery"
```

---

## Self-review notes

**Spec coverage:** generator → Task 1; mailable and template → Task 2; `issue()` with persist/send/audit and the failure path → Task 3; `store()` rewiring and the `credentials_emailed` payload → Task 4; the guarded resend endpoint including the archived and already-activated cases → Task 5; all three frontend files → Task 6; the "existing tests to update" item from the spec → Tasks 4 and 7.

**Known uncertainties flagged inline rather than guessed:** the `AuditTrail` primary key column (Task 3), the login route's field names (Task 4), `confirmAction`'s signature (Task 6), and whether `must_change_password` is hidden on the `User` model (Task 6). Each has a command to check and instructions on what to do with the answer.

**Deliberate deviation from the spec:** the spec says a resend on an archived user returns `422`. Implicit route-model binding with `SoftDeletes` returns `404` first, so the test asserts `404` and the controller keeps the `422` guard for any non-bound call path. This is a more accurate description of the same intent, not a behaviour change.
