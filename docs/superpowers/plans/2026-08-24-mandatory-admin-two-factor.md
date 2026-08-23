# Mandatory Admin Two-Factor Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make two-factor authentication unconditional for admins and independent of the global toggle, deliver every login code to the user's `recovery_email`, and stop storing the code in plaintext.

**Architecture:** A `TwoFactorPolicy` service owns the "who needs a code" rule; a `LoginOtpIssuer` service owns generating, hashing, storing, and mailing it. `AuthController::login()` consults the policy and gets three outcomes instead of two — token, OTP challenge, or a request for a missing recovery address.

**Tech Stack:** Laravel 11 (PHP 8.2), Sanctum, PHPUnit with `DatabaseTransactions`, React 19 (Create React App), plain CSS.

**Spec:** `docs/superpowers/specs/2026-08-23-mandatory-admin-two-factor-design.md`

---

## Background an engineer needs before starting

**Run backend tests from `back-end/`:** `php artisan test --filter=SomeTestClass`

`back-end/phpunit.xml` sets `APP_ENV=testing` and `MAIL_MAILER=array`. Tests use
`DatabaseTransactions` (NOT `RefreshDatabase`) against a real seeded database.

**Role IDs (verified against the live database):** `1 = admin`, `2 =
inventory_analyst`, `3 = branch_manager`, `4 = warehouse_personnel`, `5 =
auditor`. Use `role_id => 1` for admin fixtures and `role_id => 3` for non-admin
fixtures. `status_id => 1` is Active.

**2FA already exists and is currently OFF.** `two_factor_auth_enabled` is `'0'`
in the live database. `SystemSetting::twoFactorAuthEnabled()` returns `true`
when the row is absent, so do not assume "off" is the default. Because it is
off, no live user is affected by the intermediate states in Tasks 2–3.

**There is no existing test coverage for login 2FA at all.** Every test in this
plan is new ground; nothing existing pins the current behaviour.

**Login requires a CAPTCHA.** `POST /api/login` validates `captcha_token` and
`captcha_answer` and rejects with 422 before checking the password. Tests must
fetch `GET /api/login/challenge` and answer `operand_a + operand_b` as a string.
`back-end/tests/Feature/Auth/FailedLoginAuditTest.php` has the established
helper — copy its approach.

**Two model defects this plan must fix, both in `back-end/app/Models/User.php`:**

1. `otp_code` is in `$fillable` but **not** in `$hidden`, so it serialises into
   API responses. Once it holds a hash that is a hash disclosure.
2. `otp_expires_at` has **no `datetime` cast**. Today's code compares it in SQL
   (`where('otp_expires_at', '>', now())`) so it works by accident. The rewrite
   compares it in PHP, which needs a real Carbon instance.

`otp_code` **is** already in `Auditable::$skipAuditKeys`, so the automatic
model audit will not capture it. No change needed there.

---

## File Structure

**Backend — create:**
- `back-end/app/Services/TwoFactorPolicy.php` — who needs a code
- `back-end/app/Services/LoginOtpIssuer.php` — generate, hash, store, mail
- `back-end/tests/Feature/Auth/AdminTwoFactorTest.php` — the whole feature

**Backend — modify:**
- `back-end/app/Models/User.php` — `isAdmin()`, `$hidden`, `$casts`
- `back-end/app/Mail/SendOTPMail.php` → renamed to `LoginOtpMail.php`
- `back-end/resources/views/emails/otp.blade.php` — name the account
- `back-end/app/Http/Controllers/Api/AuthController.php` — `login()`,
  `verifyOtp()`, new `submitRecoveryEmail()`
- `back-end/routes/api.php` — one route

**Frontend — modify:**
- `frontend/src/admin/services/api.js` — `submitRecoveryEmail`
- `frontend/src/admin/pages/Login.jsx` — the new screen
- `frontend/src/admin/pages/SystemConfiguration.jsx` — relabel the toggle

**Frontend — create:**
- `frontend/src/admin/pages/Login.test.jsx`

**Deviation from the spec, deliberate:** the spec says create a new
`App\Mail\LoginOtpMail`. `SendOTPMail` has exactly one caller
(`AuthController.php:194`) and would be left orphaned. **Rename** it to
`LoginOtpMail` instead — same spec-intended name, no dead code, and git tracks
the rename.

---

### Task 1: `User::isAdmin()` and `TwoFactorPolicy`

Pure decision logic, no I/O. Build and test it before anything depends on it.

**Files:**
- Modify: `back-end/app/Models/User.php`
- Create: `back-end/app/Services/TwoFactorPolicy.php`
- Test: `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
```

> **Note on `SystemSetting::setTwoFactorAuthEnabled()` and `DatabaseTransactions`:**
> the write is inside the test transaction and rolls back, so it will not
> corrupt the live setting. Verify this — run the test twice and confirm the
> live value is still `'0'` afterwards with:
> `php artisan tinker --execute="var_export(\App\Models\SystemSetting::twoFactorAuthEnabled());"`
> If it changed, STOP and report it: the suite would be mutating production
> configuration.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: FAIL — `Call to undefined method App\Models\User::isAdmin()`.

- [ ] **Step 3: Add `isAdmin()` to the User model**

In `back-end/app/Models/User.php`, add near the other helpers:

```php
    /**
     * The admin role reaches every feature in the system, so several places ask
     * this question. The string comparison was already duplicated across at
     * least five files; new code asks here instead of copying it a sixth time.
     */
    public function isAdmin(): bool
    {
        return $this->role?->role_name === 'admin';
    }
```

Leave the existing five call sites alone — rewriting them is unrelated
refactoring and not part of this change.

- [ ] **Step 4: Create the policy**

Create `back-end/app/Services/TwoFactorPolicy.php`:

```php
<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Decides who must clear a second factor at login.
 *
 * Admins are deliberately exempt from the global toggle. The admin UI can turn
 * two-factor off, and before this the account with the most to lose could
 * therefore disable its own protection. Keeping the rule here rather than
 * inline in AuthController means there is exactly one place to read when
 * someone asks "which roles are covered?", and it can be tested without going
 * through HTTP.
 */
class TwoFactorPolicy
{
    public function requiresOtp(User $user): bool
    {
        return $user->isAdmin() || SystemSetting::twoFactorAuthEnabled();
    }

    /**
     * True when the user must clear a second factor but has no address to
     * receive it at. Callers collect one before issuing a code, rather than
     * mailing into the void or locking the account out.
     */
    public function requiresRecoveryEmail(User $user): bool
    {
        return $this->requiresOtp($user) && blank($user->recovery_email);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Confirm the live setting is untouched**

```bash
cd back-end && php artisan tinker --execute="var_export(\App\Models\SystemSetting::twoFactorAuthEnabled());"
```

Expected: `false`. Report the actual value.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Models/User.php back-end/app/Services/TwoFactorPolicy.php back-end/tests/Feature/Auth/AdminTwoFactorTest.php
git commit -m "feat(auth): add a two-factor policy that always covers admins"
```

---

### Task 2: `LoginOtpIssuer`, hashed storage, and the renamed mailable

**Files:**
- Create: `back-end/app/Services/LoginOtpIssuer.php`
- Rename: `back-end/app/Mail/SendOTPMail.php` → `back-end/app/Mail/LoginOtpMail.php`
- Modify: `back-end/resources/views/emails/otp.blade.php`
- Modify: `back-end/app/Models/User.php` (`$hidden`, `$casts`)
- Test: `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`

- [ ] **Step 1: Write the failing tests**

Add these imports to the test file:

```php
use App\Mail\LoginOtpMail;
use App\Services\LoginOtpIssuer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
```

Append these methods:

```php
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

        app(LoginOtpIssuer::class)->issue($user);

        // The cast matters: verifyOtp compares this in PHP, not SQL.
        $expiry = $user->fresh()->otp_expires_at;
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $expiry);
        $this->assertTrue($expiry->isFuture());
    }

    public function test_the_mail_names_the_account_the_code_is_for(): void
    {
        $user = $this->makeAdmin();

        // The code now lands in a PERSONAL inbox that may receive codes for more
        // than one system. A bare six digits does not tell the reader what they
        // are approving.
        $rendered = (new LoginOtpMail('123456', $user))->render();

        $this->assertStringContainsString('123456', $rendered);
        $this->assertStringContainsString($user->email, $rendered);
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

        $json = json_encode($user->fresh()->toArray());

        $this->assertStringNotContainsString('otp_code', $json);
    }
```

- [ ] **Step 2: Run to verify failure**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: FAIL — `Class "App\Mail\LoginOtpMail" does not exist`.

- [ ] **Step 3: Rename the mailable**

```bash
git mv back-end/app/Mail/SendOTPMail.php back-end/app/Mail/LoginOtpMail.php
```

Then replace its contents with:

```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time code for a login attempt.
 *
 * Carries the user so the message can name the account it belongs to: this now
 * arrives at a personal inbox, where six unexplained digits give the reader no
 * way to tell what they are approving.
 *
 * Send synchronously. Do NOT queue it -- the code is short-lived and a queued
 * send would leave it sitting in the jobs table.
 */
class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public User $user,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Airking Inventory login code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
```

- [ ] **Step 4: Name the account in the template**

In `back-end/resources/views/emails/otp.blade.php`, replace this block:

```blade
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                Use the code below to sign in. It will expire shortly for your security.
                            </p>
```

with:

```blade
                            <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #3f3f46;">
                                Use the code below to sign in as
                                <strong>{{ $user->email }}</strong>. It expires in 10 minutes.
                            </p>
```

- [ ] **Step 5: Fix the two model defects**

In `back-end/app/Models/User.php`, add `'otp_code'` to `$hidden`:

```php
    protected $hidden = [
        'password_hash',
        'remember_token',
        // Holds a hash of the live login code. Not a password, but there is no
        // reason for any client to receive it, and it used to serialise freely.
        'otp_code',
    ];
```

and add the datetime cast:

```php
    protected $casts = [
        'email_verified_at' => 'datetime',
        'alerts_last_seen_at' => 'datetime',
        'must_change_password' => 'boolean',
        // verifyOtp() compares this in PHP now that the code is hashed and can
        // no longer be matched in the query. Without the cast it reads back as
        // a string and every comparison silently misbehaves.
        'otp_expires_at' => 'datetime',
    ];
```

- [ ] **Step 6: Create the issuer**

Create `back-end/app/Services/LoginOtpIssuer.php`:

```php
<?php

namespace App\Services;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mints the one-time login code, stores it hashed, and mails it to the user's
 * recovery address.
 *
 * Both the login path and the recovery-address bootstrap issue codes. Keeping
 * that here means the two cannot drift apart on code length, expiry, or
 * delivery target.
 *
 * The plaintext code exists only as a local variable and in the outgoing
 * message. Only its hash is persisted, so a database read cannot complete a
 * login.
 */
class LoginOtpIssuer
{
    private const TTL_MINUTES = 10;

    /**
     * @return bool whether the message was actually accepted for delivery
     */
    public function issue(User $user): bool
    {
        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'otp_code'       => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ])->save();

        try {
            Mail::to($user->recovery_email)->send(new LoginOtpMail($code, $user));
        } catch (\Throwable $e) {
            // Never include $code: this line reaches a log file that far more
            // people can read than can read the user's inbox. report() sends the
            // real exception onward so a broken mailable is distinguishable from
            // a dead SMTP host.
            report($e);
            Log::warning('login_otp_mail_failed', [
                'user_id'   => $user->user_id,
                'exception' => get_class($e),
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
```

- [ ] **Step 7: Point the existing caller at the renamed class**

In `back-end/app/Http/Controllers/Api/AuthController.php`, change the import
`use App\Mail\SendOTPMail;` to `use App\Mail\LoginOtpMail;` and update line ~194
so the file still parses. Task 3 replaces this block entirely — for now just
make it compile:

```php
            Mail::to($user->email)->send(new LoginOtpMail($otp, $user));
```

- [ ] **Step 8: Run the tests**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: PASS, 10 tests.

- [ ] **Step 9: Commit**

```bash
git add back-end/app back-end/resources/views/emails/otp.blade.php back-end/tests/Feature/Auth/AdminTwoFactorTest.php
git commit -m "feat(auth): hash login codes and mail them to the recovery address"
```

---

### Task 3: Rewrite `verifyOtp` for hashed codes, and make the code brute-force resistant

> **Scope added during execution, after the Task 2 quality review.** The
> original Task 3 only rewrote the comparison. Review established that the
> existing defences do not hold up:
>
> The keyspace is 900,000 and the code lives 10 minutes. `POST /api/verify-otp`
> carries `throttle:12,1`, which for an unauthenticated route keys on
> **`$request->ip()`** — the one variable an attacker controls. One IP gets 120
> guesses per window (P ≈ 0.013%), but a 500-IP proxy pool gets 60,000
> (**≈6.4%**) and 7,500 IPs gets **≈63%**. Pools that size cost single-digit
> dollars.
>
> This matters precisely because the threat model for 2FA *is* "the attacker
> already has the password" — which is exactly when they can trigger codes at
> will. Note the asymmetry it creates: `login()` rate-limits the **first**
> factor per account, while the **second** factor is limited only per IP. The
> weaker gate guards the more valuable step.
>
> bcrypt is not a substitute. `BCRYPT_ROUNDS=12` is set in `.env`, and hashcat
> on one RTX 4090 covers the full 900,000-candidate keyspace in roughly 10
> minutes — the same order as the TTL itself.
>
> Task 3 therefore also adds an **account-keyed rate limit** and a **per-code
> attempt counter**. The counter is the more valuable of the two because it is
> IP-independent by construction: five wrong guesses burn the code, full stop.

**Files:**
- Create: `back-end/database/migrations/2026_08_24_000000_add_otp_attempts_to_users.php`
- Modify: `back-end/app/Models/User.php` (`$fillable`)
- Modify: `back-end/app/Services/LoginOtpIssuer.php` (reset the counter on issue)
- Modify: `back-end/app/Http/Controllers/Api/AuthController.php` (`verifyOtp`)
- Modify: `back-end/routes/api.php` (account-keyed limiter)
- Test: `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`

- [ ] **Step 1: Write the failing tests**

Append:

```php
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

        // The real code survives a wrong guess -- a typo must not burn it.
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

        $user->forceFill(['otp_expires_at' => now()->subMinute()])->save();

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
```

- [ ] **Step 2: Run to verify failure**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: the four new tests FAIL — `verifyOtp` still matches `otp_code`
against the plaintext in the query, which can never equal a hash.

- [ ] **Step 3: Rewrite `verifyOtp`**

Replace the lookup and guard at the top of `verifyOtp()`:

```php
        $user = User::where('email', $request->email)
            ->where('otp_code', $otp)
            ->where('otp_expires_at', '>', now())
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }
```

with:

```php
        // The stored value is a hash, so the code cannot be matched in the
        // query any more. Resolve the account first, then compare in PHP.
        $user = User::where('email', $request->email)->first();

        $pending = $user
            && filled($user->otp_code)
            && $user->otp_expires_at
            && $user->otp_expires_at->isFuture();

        if (! $pending || ! Hash::check($otp, $user->otp_code)) {
            // One message for every failure mode: a caller must not be able to
            // tell "no such account" from "wrong code" from "expired".
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }
```

`Hash` is already imported in this controller. Leave the rest of the method
unchanged — it already clears the code, issues a token, and audits.

- [ ] **Step 4: Run the tests**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: PASS.

- [ ] **Step 5: Commit the hashed comparison**

```bash
git add back-end/app/Http/Controllers/Api/AuthController.php back-end/tests/Feature/Auth/AdminTwoFactorTest.php
git commit -m "feat(auth): verify login codes against the stored hash"
```

- [ ] **Step 6: Write the failing tests for the attempt counter**

Append to `AdminTwoFactorTest`:

```php
    /**
     * Five wrong guesses burn the code. This is the defence that does not care
     * how many IP addresses the attacker has: a 6-digit code in a 900,000
     * keyspace is guessable inside its 10-minute window from a cheap proxy
     * pool, and per-IP throttling cannot stop that. Capping attempts per issued
     * code can.
     */
    public function test_five_wrong_guesses_burn_the_code(): void
    {
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
```

- [ ] **Step 7: Run to verify failure**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: FAIL — there is no `otp_attempts` column.

- [ ] **Step 8: Add the migration**

Create `back-end/database/migrations/2026_08_24_000000_add_otp_attempts_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Wrong guesses against the CURRENT login code. Per-IP throttling
            // cannot cap a 6-digit code against a proxy pool; capping attempts
            // per issued code can, and does so regardless of source address.
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('otp_attempts');
        });
    }
};
```

Run it against both databases:

```bash
cd back-end && php artisan migrate
cd back-end && php artisan migrate --env=testing
```

If the second command errors because there is no separate testing database, say so and report — `phpunit.xml` may point at the same schema, in which case one run is enough.

- [ ] **Step 9: Add `otp_attempts` to `$fillable` and reset it on issue**

In `back-end/app/Models/User.php`, add `'otp_attempts',` to `$fillable` beside `'otp_expires_at'`.

In `back-end/app/Services/LoginOtpIssuer.php`, add the reset to the existing `fill()`:

```php
        $user->fill([
            'otp_code'       => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            // A new code is a new budget. Carrying failures across would leave a
            // user who fumbled once permanently closer to a burned code.
            'otp_attempts'   => 0,
        ])->save();
```

- [ ] **Step 10: Enforce the cap in `verifyOtp`**

In `verifyOtp()`, add the constant to the class:

```php
    private const MAX_OTP_ATTEMPTS = 5;
```

and replace the failure branch you wrote in Step 3 with:

```php
        if (! $pending || ! Hash::check($otp, $user->otp_code)) {
            if ($pending) {
                // Only count guesses against a live code. Attempts on an
                // already-burned or expired code must not accumulate, or the
                // next legitimate code starts with a spent budget.
                $attempts = (int) $user->otp_attempts + 1;

                $user->fill([
                    'otp_attempts' => $attempts,
                    // Burn the code outright at the cap. Leaving it live but
                    // refusing guesses would let the attacker simply wait.
                    'otp_code'     => $attempts >= self::MAX_OTP_ATTEMPTS ? null : $user->otp_code,
                ])->save();
            }

            // One message for every failure mode: a caller must not be able to
            // tell "no such account" from "wrong code" from "expired" from
            // "burned".
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }
```

Confirm the success path further down already clears `otp_code` and
`otp_expires_at`, and add `'otp_attempts' => 0` to that same update.

- [ ] **Step 11: Add the account-keyed rate limiter**

Per-IP throttling is the wrong key — the attacker chooses the IP. Add a named
limiter that caps by **submitted email** as well.

In `back-end/app/Providers/AppServiceProvider.php` (check whether the project
defines limiters elsewhere first — search for `RateLimiter::for` and follow the
local pattern), register:

```php
        RateLimiter::for('verify-otp', function (Request $request) {
            return [
                // The account-keyed limit is the one that actually caps the
                // attack; the IP limit is a secondary courtesy.
                Limit::perMinute(5)->by('otp:' . Str::lower((string) $request->input('email'))),
                Limit::perMinute(12)->by($request->ip()),
            ];
        });
```

with imports `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`,
`Illuminate\Support\Facades\RateLimiter`, `Illuminate\Support\Str`.

Then in `back-end/routes/api.php`, change the verify-otp route's middleware from
`throttle:12,1` to `throttle:verify-otp`.

> **The rate limiter uses the cache store, which `DatabaseTransactions` does NOT
> roll back.** Tests that make five or more verify calls in a minute can trip it
> and start returning 429 instead of 422. If that happens, call
> `RateLimiter::clear()` for the relevant keys in the test, or add
> `$this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)`
> where the test is about the attempt counter rather than the limiter — but keep
> at least one test that exercises the limiter for real. Report which you did.

- [ ] **Step 12: Run the tests**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: PASS.

- [ ] **Step 13: Verify the cap actually bites**

Temporarily raise `MAX_OTP_ATTEMPTS` to 500, re-run
`test_five_wrong_guesses_burn_the_code`, and confirm it FAILS. Restore and
confirm `git diff` is clean. Report both outputs.

- [ ] **Step 14: Commit**

```bash
git add back-end/app back-end/database/migrations back-end/routes/api.php back-end/tests/Feature/Auth/AdminTwoFactorTest.php
git commit -m "feat(auth): cap login code attempts and rate limit by account"
```

---

### Task 4: Wire `login()` to the policy

This is where behaviour visibly changes.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AuthController.php` (`login`)
- Test: `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`

- [ ] **Step 1: Write the failing tests**

Append this CAPTCHA helper and the tests:

```php
    /**
     * POST /api/login gates on a stateless math CAPTCHA. Solve it the way the
     * real client does rather than bypassing the endpoint.
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

        // Nothing was written and nothing was sent: this is a request for
        // information, not a failed challenge.
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
```

> **If `/api/login/challenge` returns a different payload shape** than
> `data.captcha_token` / `data.operand_a` / `data.operand_b`, check
> `back-end/tests/Feature/Auth/FailedLoginAuditTest.php` for the established
> helper and match it. Report what the real shape is.

- [ ] **Step 2: Run to verify failure**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: the admin-with-toggle-off test FAILS with a token in the response —
`login()` still consults `SystemSetting` directly.

- [ ] **Step 3: Replace the 2FA block in `login()`**

Add the import:

```php
use App\Services\LoginOtpIssuer;
use App\Services\TwoFactorPolicy;
```

Replace this block:

```php
        if (SystemSetting::twoFactorAuthEnabled()) {
            $otp = (string) random_int(100000, 999999);

            $user->update([
                'otp_code' => $otp,
                'otp_expires_at' => now()->addMinutes(10),
            ]);

            Mail::to($user->email)->send(new LoginOtpMail($otp, $user));

            return response()->json([
                'requires_otp' => true,
                'email' => $user->email,
                'message' => 'OTP has been sent to your email.',
            ]);
        }
```

with:

```php
        $policy = app(TwoFactorPolicy::class);

        if ($policy->requiresRecoveryEmail($user)) {
            // The account must clear a second factor but has nowhere to receive
            // it. Collect an address rather than mailing into the void -- for an
            // admin, refusing here would mean locking the system's owner out of
            // it with no self-service way back in.
            return response()->json([
                'requires_recovery_email' => true,
                'email'   => $user->email,
                'message' => 'Add a personal recovery email to continue signing in.',
            ]);
        }

        if ($policy->requiresOtp($user)) {
            if (! app(LoginOtpIssuer::class)->issue($user)) {
                // Fail closed. No code was delivered, so no code can be entered.
                return response()->json([
                    'message' => 'We could not send your login code to '
                        . $user->recovery_email . '. Contact your administrator.',
                ], 503);
            }

            return response()->json([
                'requires_otp' => true,
                'email'   => $user->email,
                'message' => 'A login code has been sent to your recovery email.',
            ]);
        }
```

- [ ] **Step 4: Run the tests**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: PASS, 19 tests.

- [ ] **Step 5: Record that a code was issued**

> **Added during execution, from the Task 2 quality review.** The existing
> `login()` 2FA branch `return`s **without** calling `logLoginAttempt()`, and
> `LoginOtpIssuer` writes no audit row. Net effect: an attacker holding a stolen
> admin password who triggers codes but never completes verification leaves **no
> trace anywhere in the system** except an email in the victim's inbox. That is
> the highest-signal event in an account-takeover investigation and it is
> currently the one that goes unrecorded.

Write the failing test first:

```php
    public function test_issuing_a_code_is_recorded_as_a_login_attempt(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin();

        $this->login($admin)->assertStatus(200);

        $row = \App\Models\LoginActivity::where('user_id', $admin->user_id)
            ->latest('login_id')
            ->first();

        $this->assertNotNull($row, 'Triggering a login code must leave a trace.');
    }
```

> **Check `LoginActivity`'s primary key** in `back-end/app/Models/LoginActivity.php`
> before running — the plan assumes `login_id`. Use whatever it actually is and
> report what you found. Also read `logLoginAttempt()`'s signature in
> `AuthController` (around line 47) rather than guessing at its arguments.

Then, in the `requiresOtp` branch you added in Step 3, call it on both paths —
before the 503 return and before the `requires_otp` return:

```php
            $this->logLoginAttempt($request, $user, false, 'Login code issued; awaiting verification.');
```

Pass `false` for `success`: the password was correct but the login is not
complete. Recording it as a success would make the activity log claim an
authentication that never finished.

- [ ] **Step 6: Confirm nothing else regressed**

```bash
cd back-end && php artisan test --filter=FailedLoginAuditTest
cd back-end && php artisan test --filter=MustChangePasswordTest
```

Expected: PASS. `FailedLoginAuditTest` asserts on `LoginActivity` rows, so the
new write may affect its expectations — read any failure carefully before
changing anything, and report it verbatim rather than adjusting the existing
test to fit.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AuthController.php back-end/tests/Feature/Auth/AdminTwoFactorTest.php
git commit -m "feat(auth): require two-factor for admins regardless of the toggle"
```

---

### Task 5: The recovery-address bootstrap endpoint

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AuthController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Auth/AdminTwoFactorTest.php`

- [ ] **Step 1: Write the failing tests**

Append:

```php
    public function test_the_bootstrap_saves_an_address_and_sends_the_code(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin(['recovery_email' => null]);
        $address = 'bootstrap_' . uniqid() . '@gmail.com';

        $this->postJson('/api/login/recovery-email', [
            'email'          => $admin->email,
            'password'       => 'Secret123!',
            'recovery_email' => $address,
        ])
            ->assertStatus(200)
            ->assertJsonPath('requires_otp', true);

        $this->assertSame($address, $admin->fresh()->recovery_email);
        Mail::assertSent(LoginOtpMail::class, fn ($m) => $m->hasTo($address));
    }

    /**
     * The pending-login state is not a credential. Knowing an email address
     * must never be enough to write a recovery address onto someone's account
     * -- that would hand an attacker the second factor.
     */
    public function test_the_bootstrap_refuses_a_wrong_password(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin(['recovery_email' => null]);

        $this->postJson('/api/login/recovery-email', [
            'email'          => $admin->email,
            'password'       => 'WrongPassword1!',
            'recovery_email' => 'attacker_' . uniqid() . '@gmail.com',
        ])->assertStatus(422);

        $this->assertNull($admin->fresh()->recovery_email);
        Mail::assertNothingSent();
    }

    public function test_the_bootstrap_rejects_an_address_already_in_use(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $taken = $this->makeUser();
        $admin = $this->makeAdmin(['recovery_email' => null]);

        $this->postJson('/api/login/recovery-email', [
            'email'          => $admin->email,
            'password'       => 'Secret123!',
            'recovery_email' => $taken->recovery_email,
        ])->assertStatus(422);

        $this->assertNull($admin->fresh()->recovery_email);
    }

    public function test_the_bootstrap_rejects_the_users_own_login_address(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin(['recovery_email' => null]);

        // Otherwise the "second factor" arrives at the address the attacker
        // already used as a username.
        $this->postJson('/api/login/recovery-email', [
            'email'          => $admin->email,
            'password'       => 'Secret123!',
            'recovery_email' => $admin->email,
        ])->assertStatus(422);

        $this->assertNull($admin->fresh()->recovery_email);
    }
```

- [ ] **Step 2: Run to verify failure**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: FAIL with 404 — the route does not exist.

- [ ] **Step 3: Add the controller method**

Add to `AuthController`, after `login()`:

```php
    /**
     * Collect a recovery address mid-login for an account that must clear a
     * second factor but has none on file, then issue the code immediately.
     *
     * Re-verifies the password. The half-finished login state is not a
     * credential, so knowing an email address must not be enough to write a
     * recovery address -- that would let an attacker point the second factor at
     * an inbox they control.
     */
    public function submitRecoveryEmail(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email|max:100',
            'password' => 'required|string|max:255',
            // Same rules UserController::store() applies, for the same reasons:
            // forgot-password resolves accounts BY this address, so it must be
            // unique across both columns and distinct from the login address.
            'recovery_email' => 'required|email|max:100|different:email'
                . '|unique:users,recovery_email|unique:users,email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password_hash)) {
            $this->logLoginAttempt($request, $user, false, 'Invalid credentials (recovery email step).');

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        if ($user->status_id !== 1) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact administrator.'],
            ]);
        }

        $user->forceFill(['recovery_email' => $validated['recovery_email']])->save();

        if (! app(LoginOtpIssuer::class)->issue($user)) {
            return response()->json([
                'message' => 'Recovery email saved, but the login code could not be sent. Try signing in again.',
            ], 503);
        }

        return response()->json([
            'requires_otp' => true,
            'email'   => $user->email,
            'message' => 'A login code has been sent to your recovery email.',
        ]);
    }
```

- [ ] **Step 4: Add the route**

In `back-end/routes/api.php`, beside the existing `/login` route (~line 74):

```php
Route::post('/login/recovery-email', [AuthController::class, 'submitRecoveryEmail'])
    ->middleware('throttle:12,1');
```

Read the surrounding lines first and match the local style — check how `/login`
itself applies its throttle.

- [ ] **Step 5: Run the tests**

```bash
cd back-end && php artisan test --filter=AdminTwoFactorTest
```

Expected: PASS, 23 tests.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AuthController.php back-end/routes/api.php back-end/tests/Feature/Auth/AdminTwoFactorTest.php
git commit -m "feat(auth): collect a missing recovery address mid-login"
```

---

### Task 6: Frontend — the recovery-address screen and the relabelled toggle

**Files:**
- Modify: `frontend/src/admin/services/api.js`
- Modify: `frontend/src/admin/pages/Login.jsx`
- Modify: `frontend/src/admin/pages/SystemConfiguration.jsx:500`
- Create: `frontend/src/admin/pages/Login.test.jsx`

- [ ] **Step 1: Add the API client method**

In `frontend/src/admin/services/api.js`, inside `authAPI` (beside `login`), add:

```js
  submitRecoveryEmail: async (email, password, recovery_email) =>
    apiRequest('/login/recovery-email', {
      method: 'POST',
      body: JSON.stringify({ email, password, recovery_email }),
    }),
```

Read the neighbouring `authAPI.login` first and match its style exactly — if it
wraps responses or handles errors differently, follow that.

- [ ] **Step 2: Handle the new response in `Login.jsx`**

Add state beside the existing `requiresOtp`:

```js
  const [requiresRecoveryEmail, setRequiresRecoveryEmail] = useState(false);
  const [recoveryEmail, setRecoveryEmail] = useState('');
  const [pendingPassword, setPendingPassword] = useState('');
```

In `handleLoginSubmit`, immediately before the existing `requires_otp` branch:

```js
      if (data.requires_recovery_email === true) {
        // Hold the password in memory only: the bootstrap endpoint re-verifies
        // it, and asking the user to retype it here would be pointless friction.
        setPendingPassword(password);
        setRequiresRecoveryEmail(true);
        setPassword('');
        setCaptchaAnswer('');
        await loadChallenge();
        return;
      }
```

Add the submit handler beside `handleVerifyOtp`:

```js
  const handleSubmitRecoveryEmail = async () => {
    setLoading(true);
    try {
      const data = await authAPI.submitRecoveryEmail(email, pendingPassword, recoveryEmail);
      setPendingPassword('');
      setRequiresRecoveryEmail(false);
      if (data.requires_otp === true) {
        toast.success(data.message || 'Check your recovery email for the code.');
        setRequiresOtp(true);
        setOtpCode('');
      }
    } catch (err) {
      setError(err.errors?.recovery_email?.[0] || err.message || 'Could not save that address.');
    } finally {
      setLoading(false);
    }
  };
```

Route it in `handleFormSubmit`, before the `requiresOtp` check:

```js
    if (requiresRecoveryEmail) {
      await handleSubmitRecoveryEmail();
      return;
    }
```

Render the screen. Place it where the OTP screen is rendered, following that
block's existing markup conventions:

```jsx
        {requiresRecoveryEmail && (
          <div className="login-field">
            <label htmlFor="recovery-email">Recovery Email</label>
            <p className="login-hint">
              Your account needs a personal email address to receive login codes.
              Use an inbox only you can read — not your work address.
            </p>
            <input
              id="recovery-email"
              type="email"
              value={recoveryEmail}
              onChange={(e) => setRecoveryEmail(e.target.value)}
              placeholder="personal@gmail.com"
              autoComplete="email"
              required
            />
          </div>
        )}
```

**Read the existing OTP block first** and match its class names and structure.
The snippet above assumes `login-field` / `login-hint`; if the file uses
different conventions, follow the file. Make sure the login email/password
inputs and the CAPTCHA are hidden while this screen is showing, exactly as they
are for the OTP step.

- [ ] **Step 3: Relabel the Security tab toggle**

In `frontend/src/admin/pages/SystemConfiguration.jsx`, at the `twoFactorAuth`
checkbox (~line 500), update the label and add a note so switching it off does
not read as "MFA is now off everywhere":

```jsx
                  <label htmlFor="twoFactorAuth">
                    Require a login code for non-admin users
                  </label>
                  <p className="ak-sub">
                    Administrators always receive a login code and are not affected
                    by this setting.
                  </p>
```

Read the surrounding markup and match it — if the existing label carries
descriptive text of its own, adapt rather than replacing wholesale.

- [ ] **Step 4: Write the frontend tests**

Create `frontend/src/admin/pages/Login.test.jsx`, following the pattern in
`frontend/src/admin/pages/AccessControlPage.test.jsx`:

```jsx
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import Login from './Login';
import { authAPI } from '../services/api';

// Verified against the real file: Login.jsx imports authAPI, useAuth from
// ../context/AuthContext, toast from ../utils/toast, and calls
// authAPI.getLoginChallenge() -- NOT loginChallenge().
jest.mock('../services/api', () => ({
  authAPI: {
    login: jest.fn(),
    getLoginChallenge: jest.fn(),
    submitRecoveryEmail: jest.fn(),
    verifyOtp: jest.fn(),
  },
}));

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ updateUser: jest.fn() }),
}));

jest.mock('../utils/toast', () => ({
  toast: { success: jest.fn(), error: jest.fn() },
}));

const renderLogin = () => render(<MemoryRouter><Login /></MemoryRouter>);

beforeEach(() => {
  authAPI.getLoginChallenge.mockResolvedValue({
    data: { captcha_token: 'tok', operand_a: 2, operand_b: 3 },
  });
});

test('asks for a recovery email when the API reports one is missing', async () => {
  authAPI.login.mockResolvedValue({ requires_recovery_email: true, email: 'a@airking.com' });

  renderLogin();
  await waitFor(() => expect(authAPI.getLoginChallenge).toHaveBeenCalled());

  await userEvent.type(screen.getByLabelText(/email/i), 'a@airking.com');
  await userEvent.type(screen.getByLabelText(/password/i), 'Secret123!');
  await userEvent.click(screen.getByRole('button', { name: /login/i }));

  await waitFor(() =>
    expect(screen.getByLabelText(/recovery email/i)).toBeInTheDocument()
  );
});

test('shows the code entry screen when the API reports a code was sent', async () => {
  authAPI.login.mockResolvedValue({ requires_otp: true, email: 'a@airking.com' });

  renderLogin();
  await waitFor(() => expect(authAPI.getLoginChallenge).toHaveBeenCalled());

  await userEvent.type(screen.getByLabelText(/email/i), 'a@airking.com');
  await userEvent.type(screen.getByLabelText(/password/i), 'Secret123!');
  await userEvent.click(screen.getByRole('button', { name: /login/i }));

  await waitFor(() =>
    expect(screen.getByLabelText(/code/i)).toBeInTheDocument()
  );
});
```

> **The mocks above are verified against the real file, but the queries are
> not.** `Login.jsx` renders its password field through
> `PasswordFieldWithToggle`, so `getByLabelText(/password/i)` may not resolve,
> and the OTP field's label is unconfirmed. Read the component's JSX and use
> whatever it actually renders — `getByPlaceholderText`, a `role`, or a test id
> you add to the new recovery-email input only. **Do not change `Login.jsx`'s
> existing markup to suit the test.** If a query cannot be made to work without
> altering the component, say so rather than weakening the assertion to
> something that would pass on any screen.

- [ ] **Step 5: Run the frontend tests**

```bash
cd frontend && npx cross-env CI=true react-scripts test --testPathPattern=Login
```

Expected: 2 passed.

- [ ] **Step 6: Verify the build compiles**

```bash
cd frontend && npm run build
```

Expected: compiles, with only the pre-existing warnings. Confirm none name
`Login.jsx` or `SystemConfiguration.jsx`.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/pages/Login.jsx frontend/src/admin/pages/Login.test.jsx frontend/src/admin/pages/SystemConfiguration.jsx
git commit -m "feat(auth): add the recovery-email login step and relabel the 2FA toggle"
```

---

### Task 7: Gate ordering, full suite, and documentation

- [ ] **Step 1: Write the gate-ordering test**

A user can now face both an OTP challenge and a forced password change. OTP must
come first. This needs no new code — `login()` returns `requires_otp` *instead
of* a token, and `CheckPasswordChange` only runs on authenticated routes — but
nothing pins it, so a refactor that issued a token earlier could silently invert
the order.

Append to `AdminTwoFactorTest`:

```php
    /**
     * Prove who you are before you are allowed to change anything. This holds
     * today because no token is issued alongside requires_otp, so the
     * CheckPasswordChange middleware is never reached. Pinned so a future
     * refactor cannot quietly reverse it.
     */
    public function test_the_code_challenge_precedes_the_forced_password_change(): void
    {
        Mail::fake();
        SystemSetting::setTwoFactorAuthEnabled(false);
        $admin = $this->makeAdmin(['must_change_password' => true]);

        $this->login($admin)
            ->assertStatus(200)
            ->assertJsonPath('requires_otp', true)
            ->assertJsonMissing(['token']);
    }
```

- [ ] **Step 2: Run the full backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS. The baseline before this plan was **1121 passed, 2 skipped, 0
failures**; this plan adds 24. If something unrelated fails, check whether it
also fails on `git stash` before touching it.

- [ ] **Step 3: Confirm the live 2FA setting was not mutated**

```bash
cd back-end && php artisan tinker --execute="var_export(\App\Models\SystemSetting::twoFactorAuthEnabled());"
```

Expected: `false`. The suite writes this setting inside transactions; if it now
reads `true`, the tests have leaked into production configuration and that must
be fixed before this ships.

- [ ] **Step 4: Check for orphaned references to the old mailable**

```bash
grep -rn "SendOTPMail" back-end/ --include=*.php
```

Expected: no results. Any hit is a broken reference the rename missed.

- [ ] **Step 5: Update `DEPLOYMENT.md`**

Under the "Email delivery (Brevo SMTP)" section, after the paragraph added for
account creation, add:

```markdown
**Administrative login now depends on mail delivery too.** Admins always receive
a one-time login code at their `recovery_email`, regardless of the "Require a
login code for non-admin users" setting — the toggle governs other roles only.
Login fails closed when the code cannot be sent, so a broken `MAIL_*`
configuration locks out every admin, including whoever would fix it. Recovery
then requires database or server console access. Verify mail delivery before
deploying this, and keep a route to the database that does not depend on the
web UI.
```

- [ ] **Step 6: Commit**

```bash
git add back-end/tests/Feature/Auth/AdminTwoFactorTest.php DEPLOYMENT.md
git commit -m "docs: record that admin login now depends on mail delivery"
```

---

## Manual verification (after Task 7)

Automated tests cannot cover the real mail round-trip or the new screen. Someone
must do this against the running app, and it needs an admin who can log in:

1. Confirm the Security tab toggle reads "Require a login code for non-admin
   users" with the note about admins.
2. With the toggle **off**, log in as an admin → a code should arrive at the
   admin's `recovery_email`, and the app should ask for it.
3. Enter the code → normal access.
4. With the toggle **off**, log in as a non-admin (`branch@airking.com`) → no
   code, straight in.
5. Turn the toggle **on**, log in as `branch@airking.com` — that account has
   **no recovery email**, so it should show the new recovery-address screen.
   Supply one and confirm the code arrives there.
6. Check the code email names the account it is for.

**Before starting step 2, make sure you can reach the database independently**
(phpMyAdmin, tinker, or a console). If mail is misconfigured, that is the only
way back in.

---

## Self-review notes

**Spec coverage:** `TwoFactorPolicy` → Task 1; `LoginOtpIssuer`, the mailable,
hashed storage, and both model defects → Task 2; hashed verification → Task 3;
the three-way login branch and fail-closed behaviour → Task 4; the bootstrap
endpoint with password re-verification and `store()`'s validation rules → Task
5; both frontend changes and `Login.test.jsx` → Task 6; gate ordering, the
orphan check, and the `DEPLOYMENT.md` risk note → Task 7.

**Deliberate deviation:** the spec says create `App\Mail\LoginOtpMail`. This plan
**renames** `SendOTPMail` to that name instead, because it has exactly one
caller and creating a parallel class would orphan it. Same resulting name, no
dead code.

**Uncertainties flagged inline rather than guessed:** the `/api/login/challenge`
payload shape (Task 4), the throttle style on `/login` (Task 5), and
`Login.jsx`'s class names and field labels (Task 6). Each names the file to read
and what to do with the answer.

**Corrected during self-review:** the frontend test originally mocked
`authAPI.loginChallenge`. The real method is `authAPI.getLoginChallenge()`
(`Login.jsx:51`), and the component also needs `../context/AuthContext` and
`../utils/toast` mocked. Task 6's mock block now matches the real file.

**Ordering note:** between Tasks 2 and 4 the code is delivered to
`recovery_email` while `login()` still consults the global toggle directly. A
user with 2FA on and no recovery address would fail in that window. This is safe
because the toggle is currently off in the live database — no user reaches that
path — and Task 4 closes it. Do not deploy a partial run of this plan.
