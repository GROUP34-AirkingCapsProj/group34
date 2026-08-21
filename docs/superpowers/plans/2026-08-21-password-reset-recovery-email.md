# Password Reset via Recovery Email + Resend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route password-reset codes to a personal `recovery_email` instead of the company login address, deliver them for real via Resend, and force employees to replace admin-assigned temporary passwords at first login.

**Architecture:** The existing OTP reset flow (`ForgotPasswordOtpController`) is kept and retargeted — three surgical edits, no restructuring. Two nullable-safe columns are added to `users`. A `CheckPasswordChange` middleware returns HTTP 403 with `code: password_change_required` on the single protected API group, with a three-route allowlist so the gate cannot deadlock; the React SPA mirrors this with a `RoleRoute` guard and a 403 branch in `apiRequest`.

**Tech Stack:** Laravel 12 (PHP 8.2), Sanctum token auth, MySQL, PHPUnit with `DatabaseTransactions`, Create React App 
+ React Router, `resend/resend-laravel`.

**Source spec:** `docs/superpowers/specs/2026-08-21-password-reset-recovery-email-design.md`

---

## File Structure

**Backend — create:**
- `back-end/database/migrations/2026_08_21_000000_add_recovery_email_and_must_change_password_to_users.php` — the two columns plus the existing-row backfill.
- `back-end/app/Http/Middleware/CheckPasswordChange.php` — the gate. One responsibility: block flagged users, allow the escape hatch.
- `back-end/tests/Feature/Auth/RecoveryEmailResetTest.php` — forgot/reset flow behaviour.
- `back-end/tests/Feature/Auth/MustChangePasswordTest.php` — flag lifecycle and middleware gate.
- `back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php` — admin create/update validation.

**Backend — modify:**
- `app/Models/User.php` — fillable, cast, `reset_mail_address` accessor, `routeNotificationForMail`.
- `app/Http/Controllers/Api/ForgotPasswordOtpController.php` — `forgot()` and `reset()`.
- `app/Http/Controllers/Api/UserController.php` — `store()`, `update()`, `changePassword()`.
- `app/Http/Controllers/Api/AccountController.php` — `updatePassword()`.
- `bootstrap/app.php` — middleware alias.
- `routes/api.php` — attach middleware to the protected group.
- `.env.example`, `composer.json`, `DEPLOYMENT.md`.

**Frontend — create:**
- `frontend/src/admin/pages/ChangePassword.jsx` — terminal forced-change screen. No sidebar, no nav.

**Frontend — modify:**
- `src/admin/components/UserManagementPanel.jsx` — recovery email field.
- `src/admin/components/RoleRoute.jsx` — flag guard.
- `src/admin/services/api.js` — 403 branch.
- `src/App.jsx` — route.
- `src/admin/pages/ForgotPassword.jsx` — copy only.

**Test conventions in this repo (follow these, do not use `UserFactory`):** `database/factories/UserFactory.php` is the stale Laravel default — it writes `name` and `password`, columns this schema does not have. Every existing feature test builds users with `User::create([...])` and a `uniqid()` suffix, uses the `DatabaseTransactions` trait (not `RefreshDatabase`), and authenticates with `$this->actingAs($user, 'sanctum')`. See `tests/Feature/Account/AccountAvatarTest.php:14-27` for the canonical shape.

**Test command:** `cd back-end && php artisan test --filter=<TestClass>`
**Test database:** `airkingV5_test` on `127.0.0.1:3306` (see `phpunit.xml:26-30`). It must exist and be migrated before running tests.

**Two environment gotchas discovered during Task 2 — read before running any artisan command:**

1. `back-end/.env` sets `APP_ENV=production`. Every `migrate`, `migrate:rollback`, and `migrate:fresh` therefore prompts for confirmation and aborts non-interactively. Always pass `--force`.
2. `php artisan migrate --env=testing` does **not** target the test database — there is no `.env.testing`, so it silently falls back to the main `.env` and reports "Nothing to migrate". To migrate `airkingV5_test`, pass the connection explicitly:

   ```bash
   cd back-end && DB_DATABASE=airkingV5_test DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD= php artisan migrate --force
   ```

   Both databases already carry the Task 2 columns; this is for any later migration.

---

## Task 1: Install and configure the Resend mail driver

No test — this is configuration. Verification is by inspection and a `php artisan config:show` call.

**Files:**
- Modify: `back-end/composer.json` (via composer)
- Modify: `back-end/.env.example:62-69`
- Modify: `DEPLOYMENT.md`

**Note:** `config/mail.php:63-65` already declares the `resend` transport and `config/services.php:21-23` already reads `env('RESEND_API_KEY')`. Both are correct as-is. Do not edit them.

- [ ] **Step 1: Install the package**

```bash
cd back-end
composer require resend/resend-laravel
```

Expected: composer resolves and writes `resend/resend-laravel` plus `resend/resend-php` into `composer.json` and `vendor/`.

- [ ] **Step 2: Verify the transport registers**

```bash
cd back-end
php artisan config:show mail.mailers.resend
```

Expected: prints `transport ......... resend`. If the command errors with "Unsupported mail transport", the package's service provider did not auto-discover — run `php artisan package:discover` and retry.

- [ ] **Step 3: Update `.env.example`**

Replace lines 62-69 of `back-end/.env.example` with:

```
MAIL_MAILER=resend
RESEND_API_KEY=your_resend_api_key_here
MAIL_FROM_ADDRESS="noreply@airking.com"
MAIL_FROM_NAME="Airking Inventory System"
```

Delete the now-unused `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` lines — the Resend transport uses none of them.

- [ ] **Step 4: Leave the local `.env` alone**

Confirm `back-end/.env:62` still reads `MAIL_MAILER=log`:

```bash
cd back-end && grep -n "^MAIL_MAILER" .env
```

Expected: `62:MAIL_MAILER=log`

This is deliberate. Local development must not consume Resend quota or mail real people. Do not change it.

- [ ] **Step 5: Document the domain requirement**

Append to `DEPLOYMENT.md`, under the environment configuration section:

```markdown
### Email delivery (Resend)

Password reset codes are delivered through Resend. Set `RESEND_API_KEY` in the
production `.env` from the Resend dashboard.

Sending from `noreply@airking.com` requires the `airking.com` domain to be
verified in Resend first: add the SPF and DKIM DNS records Resend generates for
the domain and wait for verification to complete. Until then, the only sender
Resend will accept is `onboarding@resend.dev` — set `MAIL_FROM_ADDRESS` to that
value if you need to test delivery before DNS propagates.

Reset mail is addressed to each user's `recovery_email`, falling back to their
login `email` when no recovery address is on file.
```

- [ ] **Step 6: Commit**

```bash
git add back-end/composer.json back-end/composer.lock back-end/.env.example DEPLOYMENT.md
git commit -m "feat(mail): install Resend driver and document domain verification"
```

---

## Task 2: Add the schema columns and User model support

**Files:**
- Create: `back-end/database/migrations/2026_08_21_000000_add_recovery_email_and_must_change_password_to_users.php`
- Modify: `back-end/app/Models/User.php:32-56`
- Create: `back-end/tests/Feature/Auth/RecoveryEmailResetTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Auth/RecoveryEmailResetTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=RecoveryEmailResetTest
```

Expected: FAIL. `Column not found: 1054 Unknown column 'recovery_email'`.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_21_000000_add_recovery_email_and_must_change_password_to_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable at the DB level so existing rows survive the deploy.
            // Validation requires it when an admin creates a user.
            // Not unique: two employees may legitimately share a household inbox.
            $table->string('recovery_email', 100)->nullable()->after('email');
            $table->boolean('must_change_password')->default(true)->after('password_hash');
        });

        // Existing users keep their passwords. Only accounts created from here
        // on are forced through a change — otherwise this deploy would lock
        // every current user, including admins, out of the system at once.
        DB::table('users')->update(['must_change_password' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['recovery_email', 'must_change_password']);
        });
    }
};
```

- [ ] **Step 4: Update the User model**

In `back-end/app/Models/User.php`, add `'recovery_email'` to `$fillable` immediately after `'email'` (line 35), and `'must_change_password'` after `'password_hash'`:

```php
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'recovery_email',
        'username',
        'password_hash',
        'must_change_password',
        'phone',
        'avatar_path',
        'address',
        'role_id',
        'location_id',
        'status_id',
        'otp_code',
        'otp_expires_at',
        'alerts_last_seen_at',
    ];
```

Add the cast to `$casts` (line 54):

```php
    protected $casts = [
        'email_verified_at' => 'datetime',
        'alerts_last_seen_at' => 'datetime',
        'must_change_password' => 'boolean',
    ];
```

Then add both methods to the class body (place them after the `$casts` block):

```php
    /**
     * Where password-reset mail goes: the employee's personal inbox, not the
     * company address they are locked out of. Falls back to the login email for
     * users created before recovery_email existed.
     */
    public function getResetMailAddressAttribute(): string
    {
        return $this->recovery_email ?: $this->email;
    }

    /**
     * Notification mail routing. Note that the reset flow does NOT go through
     * this method — it sends a Mailable via Mail::to(), and Laravel consults
     * notification routing only for Notifications. Kept so any future
     * $user->notify() reaches the recovery address too.
     */
    public function routeNotificationForMail($notification)
    {
        return $this->recovery_email ?: $this->email;
    }
```

- [ ] **Step 5: Run the migration on dev and test databases**

```bash
cd back-end
php artisan migrate
php artisan migrate --database=mysql --env=testing
```

Expected: `2026_08_21_000000_add_recovery_email_and_must_change_password_to_users ... DONE` for both.

If the second command errors because `airkingV5_test` does not exist, create it first:
`mysql -u root -e "CREATE DATABASE IF NOT EXISTS airkingV5_test;"` then re-run.

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=RecoveryEmailResetTest
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
git add back-end/database/migrations/2026_08_21_000000_add_recovery_email_and_must_change_password_to_users.php back-end/app/Models/User.php back-end/tests/Feature/Auth/RecoveryEmailResetTest.php
git commit -m "feat(users): add recovery_email and must_change_password columns"
```

---

## Task 3: Require recovery_email on admin employee creation

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/UserController.php` (`store()`, `update()`)
- Create: `back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RecoveryEmailValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeAdmin(): User
    {
        $uid = uniqid();

        return User::create([
            'first_name'    => 'Ad',
            'last_name'     => 'Min',
            'username'      => 'admin_' . $uid,
            'email'         => 'admin_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $uid = uniqid();

        return array_merge([
            'first_name'            => 'New',
            'last_name'             => 'Hire',
            'username'              => 'hire_' . $uid,
            'email'                 => 'hire_' . $uid . '@airking.com',
            'recovery_email'        => 'hire_' . $uid . '@gmail.com',
            'password'              => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role_id'               => 1,
            'status_id'             => 1,
        ], $overrides);
    }

    public function test_store_requires_recovery_email(): void
    {
        $payload = $this->payload();
        unset($payload['recovery_email']);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.recovery_email.0', fn ($m) => is_string($m));
    }

    public function test_store_rejects_recovery_email_equal_to_login_email(): void
    {
        $payload = $this->payload();
        $payload['recovery_email'] = $payload['email'];

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.recovery_email.0', fn ($m) => is_string($m));
    }

    public function test_store_persists_recovery_email_and_forces_password_change(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(201);

        $created = User::where('email', $payload['email'])->first();

        $this->assertNotNull($created);
        $this->assertSame($payload['recovery_email'], $created->recovery_email);
        $this->assertTrue($created->must_change_password);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=RecoveryEmailValidationTest
```

Expected: FAIL. The first two tests get 201 instead of 422 — no rule rejects the missing or duplicate address yet.

- [ ] **Step 3: Add the validation rules**

In `UserController::store()`, add one line to the `$request->validate([...])` array, directly after the `'email'` rule:

```php
                'email' => 'required|email|unique:users|max:100',
                // Personal inbox for password recovery. `different:email` stops an
                // admin pasting the company address into both fields, which would
                // silently defeat the whole mechanism.
                'recovery_email' => 'required|email|max:100|different:email',
```

In `UserController::update()`, add the same rule as optional so admins can backfill existing users. Locate the `$request->validate([...])` array in `update()` and add:

```php
                'recovery_email' => 'sometimes|email|max:100|different:email',
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=RecoveryEmailValidationTest
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/UserController.php back-end/tests/Feature/Auth/RecoveryEmailValidationTest.php
git commit -m "feat(users): require a distinct recovery_email when creating employees"
```

---

## Task 4: Route reset codes to the recovery inbox and stop email enumeration

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ForgotPasswordOtpController.php` (`forgot()`, lines 30-77)
- Modify: `back-end/tests/Feature/Auth/RecoveryEmailResetTest.php`

- [ ] **Step 1: Write the failing tests**

Add these three methods to the `RecoveryEmailResetTest` class created in Task 2. Also add the two imports at the top of the file, beneath the existing `use App\Models\User;`:

```php
use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use Illuminate\Support\Facades\Mail;
```

```php
    public function test_forgot_sends_otp_to_recovery_email(): void
    {
        Mail::fake();
        $user = $this->makeUser(['recovery_email' => 'personal@gmail.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) {
            return $mail->hasTo('personal@gmail.com');
        });
    }

    public function test_forgot_falls_back_to_login_email_when_no_recovery_address(): void
    {
        Mail::fake();
        $user = $this->makeUser(['recovery_email' => null]);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(200);

        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_forgot_does_not_reveal_whether_an_account_exists(): void
    {
        Mail::fake();

        $known = $this->makeUser(['recovery_email' => 'personal@gmail.com']);
        $unknownEmail = 'nobody_' . uniqid() . '@airking.com';

        $hit = $this->postJson('/api/forgot-password', ['email' => $known->email]);
        $miss = $this->postJson('/api/forgot-password', ['email' => $unknownEmail]);

        $hit->assertStatus(200);
        $miss->assertStatus(200);
        // Byte-identical bodies: nothing distinguishes a real account from a miss.
        $this->assertSame($hit->json(), $miss->json());

        // And no challenge row is created for the address that does not exist.
        $this->assertSame(0, PasswordResetOtp::where('email', $unknownEmail)->count());
        Mail::assertSentCount(1);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test --filter=RecoveryEmailResetTest
```

Expected: FAIL on all three new tests. `test_forgot_sends_otp_to_recovery_email` fails because mail goes to `$user->email`; `test_forgot_does_not_reveal_whether_an_account_exists` fails with 422 on the miss.

- [ ] **Step 3: Rewrite the top half of `forgot()`**

In `ForgotPasswordOtpController::forgot()`, replace this block:

```php
        $user = User::where('email', $email)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['No account found with that email address.'],
            ]);
        }
```

with:

```php
        // Uniform response whether or not the account exists. Returning a
        // "no account found" error here would let anyone probe which addresses
        // are real accounts. The success body below carries no hint of which
        // inbox was used, or whether one was used at all.
        $genericResponse = response()->json([
            'message' => 'A reset link has been sent to your registered recovery email address.',
            'expires_in_minutes' => self::OTP_TTL_MINUTES,
        ]);

        $user = User::where('email', $email)->first();
        if (! $user) {
            return $genericResponse;
        }
```

Then change the mail line from:

```php
            Mail::to($user->email)->send(new PasswordResetOtpMail($plainOtp));
```

to:

```php
            Mail::to($user->reset_mail_address)->send(new PasswordResetOtpMail($plainOtp));
```

Finally, replace the method's closing `return response()->json([...])` with:

```php
        return $genericResponse;
```

Leave the `catch (\Throwable $e)` mail-failure block exactly as it is. It must keep returning its validation error — but it can now only fire for a user that genuinely exists, since the unknown-user case returns before reaching it.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=RecoveryEmailResetTest
```

Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ForgotPasswordOtpController.php back-end/tests/Feature/Auth/RecoveryEmailResetTest.php
git commit -m "feat(auth): mail reset codes to recovery_email and stop email enumeration"
```

---

## Task 5: Clear the forced-change flag on every successful password change

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/ForgotPasswordOtpController.php` (`reset()`)
- Modify: `back-end/app/Http/Controllers/Api/AccountController.php` (`updatePassword()`)
- Modify: `back-end/app/Http/Controllers/Api/UserController.php` (`changePassword()`)
- Create: `back-end/tests/Feature/Auth/MustChangePasswordTest.php`

**Design note — a refinement over the spec:** `UserController::changePassword()` serves two cases. When a user changes their *own* password the flag clears. When an *admin* changes *someone else's* password, the new password is an admin-assigned temporary credential, so the flag is **set to true** — the same reasoning that makes it default to true on creation. The spec listed this call site as clearing the flag unconditionally; this splits it by case.

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Auth/MustChangePasswordTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MustChangePasswordTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Must',
            'last_name'            => 'Change',
            'username'             => 'must_' . $uid,
            'email'                => 'must_' . $uid . '@airking.com',
            'recovery_email'       => 'must_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => true,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    public function test_self_service_password_update_clears_the_flag(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])
            ->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_forgot_password_reset_clears_the_flag(): void
    {
        $user = $this->makeUser();

        $resetToken = Str::random(64);
        PasswordResetOtp::create([
            'email'          => $user->email,
            'otp_hash'       => Hash::make('123456'),
            'expires_at'     => now()->addMinutes(10),
            'verified_token' => $resetToken,
        ]);

        $this->postJson('/api/reset-password', [
            'email'                 => $user->email,
            'reset_token'           => $resetToken,
            'password'              => 'Brandnew123!',
            'password_confirmation' => 'Brandnew123!',
        ])->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_admin_setting_another_users_password_re_arms_the_flag(): void
    {
        $admin = $this->makeUser(['must_change_password' => false]);
        $target = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $target->user_id . '/change-password', [
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])
            ->assertStatus(200);

        $this->assertTrue($target->fresh()->must_change_password);
    }
}
```

**Note on `test_admin_setting_another_users_password_re_arms_the_flag`:** `changePassword()` requires the caller to be the target or hold the `admin` role. `role_id => 1` is the admin role in this schema (every existing feature test uses it for privileged users). If this test 403s, check `roles` for the actual admin `role_id` and adjust the two `makeUser` calls.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end && php artisan test --filter=MustChangePasswordTest
```

Expected: FAIL on all three. The flag stays `true` after the first two, and stays `false` after the third.

- [ ] **Step 3: Clear the flag in `ForgotPasswordOtpController::reset()`**

Inside the existing `DB::transaction(...)` closure, change:

```php
            $user->update([
                'password_hash' => Hash::make($validated['password']),
            ]);
```

to:

```php
            $user->update([
                'password_hash' => Hash::make($validated['password']),
                // The user has now chosen their own password; release the gate.
                'must_change_password' => false,
            ]);
```

- [ ] **Step 4: Clear the flag in `AccountController::updatePassword()`**

Change:

```php
            $user->update(['password_hash' => Hash::make($validated['new_password'])]);
```

to:

```php
            $user->update([
                'password_hash' => Hash::make($validated['new_password']),
                // This endpoint is also what the forced-change screen posts to.
                'must_change_password' => false,
            ]);
```

- [ ] **Step 5: Split the two cases in `UserController::changePassword()`**

Change:

```php
            $user->update([
                'password_hash' => Hash::make($validated['new_password'])
            ]);
```

to:

```php
            // Self-service change releases the gate. An admin setting someone
            // else's password is issuing a temporary credential, so it re-arms
            // the gate — same reasoning as a freshly created account.
            $isSelf = $request->user()->user_id === $user->user_id;

            $user->update([
                'password_hash' => Hash::make($validated['new_password']),
                'must_change_password' => ! $isSelf,
            ]);
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end && php artisan test --filter=MustChangePasswordTest
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/ForgotPasswordOtpController.php back-end/app/Http/Controllers/Api/AccountController.php back-end/app/Http/Controllers/Api/UserController.php back-end/tests/Feature/Auth/MustChangePasswordTest.php
git commit -m "feat(auth): clear must_change_password on every self-service password change"
```

---

## Task 6: Build the CheckPasswordChange gate

**Files:**
- Create: `back-end/app/Http/Middleware/CheckPasswordChange.php`
- Modify: `back-end/bootstrap/app.php:39-48`
- Modify: `back-end/routes/api.php:95`
- Modify: `back-end/tests/Feature/Auth/MustChangePasswordTest.php`

- [ ] **Step 1: Write the failing tests**

Add these four methods to `MustChangePasswordTest`:

```php
    public function test_flagged_user_is_blocked_from_a_protected_route(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_unflagged_user_passes_through(): void
    {
        $user = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(200);
    }

    public function test_flagged_user_can_still_reach_the_escape_hatch_routes(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        // Identity endpoint — the SPA needs this to know why it is being blocked.
        $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertStatus(200);

        // Changing the password is the whole point of the gate.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])
            ->assertStatus(200);
    }

    public function test_flagged_user_can_log_out(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/logout')
            ->assertStatus(200);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end && php artisan test --filter=MustChangePasswordTest
```

Expected: FAIL on `test_flagged_user_is_blocked_from_a_protected_route` — it gets 200 because no gate exists yet. The other three should already pass; they are the regression guard for Step 3.

- [ ] **Step 3: Write the middleware**

Create `back-end/app/Http/Middleware/CheckPasswordChange.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Blocks users holding an admin-assigned temporary password until they choose
 * their own. Applied group-wide in routes/api.php, so the routes a blocked user
 * still needs are exempted here rather than by per-route registration.
 */
class CheckPasswordChange
{
    /**
     * Routes a flagged user must still reach. Without these the gate deadlocks:
     * the SPA could not load the identity that explains the block, the user
     * could not submit a new password, and could not even sign out.
     */
    private const EXEMPT_ROUTES = [
        'POST /api/logout',
        'GET /api/user',
        'PUT /api/me/password',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $signature = $request->method() . ' /' . ltrim($request->path(), '/');

        if (in_array($signature, self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You must change your password before continuing.',
            // Machine-readable so the SPA can redirect rather than guess from
            // the message text.
            'code' => 'password_change_required',
        ], 403);
    }
}
```

- [ ] **Step 4: Register the alias**

In `back-end/bootstrap/app.php`, add one entry to the `$middleware->alias([...])` array (after line 47):

```php
            'permission'             => \App\Http\Middleware\CheckPermission::class,
            'password.changed'       => \App\Http\Middleware\CheckPasswordChange::class,
```

- [ ] **Step 5: Attach it to the protected group**

In `back-end/routes/api.php`, line 95, add `'password.changed'` to the group's middleware array:

```php
Route::middleware(['auth:sanctum', 'auditor.readonly', 'location.scope', 'extend.sanctum.session', 'password.changed'])->group(function () {
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
cd back-end && php artisan test --filter=MustChangePasswordTest
```

Expected: PASS, 7 tests. If `test_flagged_user_can_still_reach_the_escape_hatch_routes` fails with 403, the request path did not match `EXEMPT_ROUTES` — dump `$request->method() . ' /' . $request->path()` inside the middleware and reconcile the string (Laravel's `path()` excludes the leading slash and any query string).

- [ ] **Step 7: Run the whole auth suite to check for regressions**

```bash
cd back-end && php artisan test --filter="Auth|Account|Rbac"
```

Expected: PASS. Any pre-existing test that authenticates a user built without `must_change_password` now gets `false` from the column default backfill only if the row existed before the migration — newly-created test users get `true` and will be gated. If existing tests fail with 403 `password_change_required`, that is the expected interaction: fix them by adding `'must_change_password' => false` to their user-creation helper.

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Middleware/CheckPasswordChange.php back-end/bootstrap/app.php back-end/routes/api.php back-end/tests/Feature/Auth/MustChangePasswordTest.php
git commit -m "feat(auth): gate the API until a temporary password is replaced"
```

---

## Task 7: Collect the recovery email in the admin user form

**Files:**
- Modify: `frontend/src/admin/components/UserManagementPanel.jsx` (lines 37-42, 124-135, 136-150, 188-200, 236-256, 496-503)

No unit test — this repo has no test for `UserManagementPanel`, and adding a React Testing Library harness for it is out of scope. Verified manually in Step 6.

- [ ] **Step 1: Add the field to the three form-state definitions**

At line 37, add `recovery_email: ''` to the initial state:

```jsx
  const [formData, setFormData] = useState({
    first_name: '', last_name: '', username: '', email: '', recovery_email: '',
    password: '', password_confirmation: '',
    phone: '', role_id: '', location_id: '', status_id: 1
  });
```

At line 126, inside `handleAdd`, make the reset object identical:

```jsx
    setFormData({
      first_name: '', last_name: '', username: '', email: '', recovery_email: '',
      password: '', password_confirmation: '',
      phone: '', role_id: '', location_id: '', status_id: 1
    });
```

At line 142, inside `handleEdit`, hydrate it from the user:

```jsx
      email: user.email || '',
      recovery_email: user.recovery_email || '',
```

- [ ] **Step 2: Add validation**

In `validateForm()`, directly after the existing `email` checks (line 195), add:

```jsx
    if (!editingUser) {
      if (!formData.recovery_email.trim()) errors.recovery_email = 'Recovery email is required';
      else if (!/\S+@\S+\.\S+/.test(formData.recovery_email)) errors.recovery_email = 'Invalid email format';
    }
    if (
      formData.recovery_email.trim()
      && formData.recovery_email.trim().toLowerCase() === formData.email.trim().toLowerCase()
    ) {
      errors.recovery_email = 'Recovery email must be different from the login email';
    }
```

Required on create, optional on edit — matching the `required` / `sometimes` split in the backend rules from Task 3.

- [ ] **Step 3: Add it to the create payload**

At line 245, after the `email` entry:

```jsx
          email: String(formData.email || '').trim(),
          recovery_email: String(formData.recovery_email || '').trim(),
```

Do the same in the update payload — locate the `usersAPI.update(...)` payload object in the same `handleSubmit` and add the identical line.

- [ ] **Step 4: Render the input**

Replace the `form-row` block at lines 496-503 so Email and Recovery Email sit side by side, and Phone moves to its own row:

```jsx
            <div className="form-row">
              <div className="form-group">
                <label>Email Address <span className="required">*</span></label>
                <input type="email" value={formData.email} onChange={(e) => setFormData({...formData, email: e.target.value})} placeholder="user@airking.com" />
                <small className="um-field-hint">Used to sign in.</small>
                {formErrors.email && <span className="um-field-error">{formErrors.email}</span>}
              </div>
              <div className="form-group">
                <label>Recovery Email <span className="required">*</span></label>
                <input type="email" value={formData.recovery_email} onChange={(e) => setFormData({...formData, recovery_email: e.target.value})} placeholder="personal@gmail.com" />
                <small className="um-field-hint">Personal inbox. Password reset codes are sent here.</small>
                {formErrors.recovery_email && <span className="um-field-error">{formErrors.recovery_email}</span>}
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Phone Number</label>
                <input type="text" value={formData.phone} onChange={(e) => setFormData({...formData, phone: e.target.value})} placeholder="e.g. 09171234567" />
```

Leave the closing tags of the original Phone `form-group` and `form-row` in place — you are only inserting a new row boundary before Phone, not deleting its markup.

- [ ] **Step 5: Add the hint style**

`UserManagementPanel.jsx` imports no stylesheet of its own — its `um-` classes live in `frontend/src/admin/styles/user_management.css` (`.um-field-error` is at line 664). Append the new rule there, beside it:

```css
.um-field-hint {
  display: block;
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: #6b7280;
}
```

- [ ] **Step 6: Verify manually**

```bash
cd frontend && npm start
```

Open the user management panel, click Add User. Expected: Email and Recovery Email render side by side with hint text. Submitting with both set to the same address shows "Recovery email must be different from the login email". Submitting a valid pair creates the user, and the created row's `recovery_email` is populated:

```bash
cd back-end && php artisan tinker --execute="echo App\Models\User::latest('user_id')->first()->recovery_email;"
```

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/UserManagementPanel.jsx frontend/src/admin/styles/user_management.css
git commit -m "feat(users): collect a recovery email in the admin user form"
```

---

## Task 8: Build the forced change-password screen and wire the client guard

**Files:**
- Create: `frontend/src/admin/pages/ChangePassword.jsx`
- Modify: `frontend/src/App.jsx:93`
- Modify: `frontend/src/admin/components/RoleRoute.jsx:12`
- Modify: `frontend/src/admin/services/api.js:270`
- Modify: `frontend/src/admin/pages/ForgotPassword.jsx:135`

- [ ] **Step 1: Create the change-password screen**

Create `frontend/src/admin/pages/ChangePassword.jsx`:

```jsx
import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { accountAPI } from '../services/api';
import { useAuth } from '../context/AuthContext';
import { toast } from '../utils/toast';
import PasswordFieldWithToggle from '../components/PasswordFieldWithToggle';
import '../styles/login.css';

/**
 * Terminal screen for users holding an admin-assigned temporary password.
 * Deliberately has no sidebar or nav: the API gates every other route until
 * this form succeeds, so offering navigation would only produce 403s.
 */
const ChangePassword = () => {
  const navigate = useNavigate();
  const { refreshUser } = useAuth();

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');

    if (newPassword !== confirmation) {
      setError('The new passwords do not match.');
      return;
    }

    setLoading(true);
    try {
      await accountAPI.updatePassword({
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: confirmation,
      });
      // Re-read /me so must_change_password flips false before RoleRoute runs
      // again — otherwise the guard bounces the user straight back here.
      await refreshUser();
      toast.success('Password updated. Welcome aboard.');
      navigate('/admin/dashboard', { replace: true });
    } catch (err) {
      setError(
        err.errors?.new_password?.[0]
        || err.errors?.current_password?.[0]
        || err.message
        || 'Could not change your password.'
      );
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-container">
        <div className="left-side">
          <div className="logo-box">
            <img src="/images/air.png" alt="AirKing Logo" className="logo" />
          </div>
          <h1>AirKing Air Conditioning</h1>
          <p className="description">
            WEB-BASED ADAPTIVE BARCODE AUDITING AND INVENTORY MANAGEMENT SYSTEM FOR AIRKING AIR CONDITIONING
          </p>
        </div>

        <div className="right-side">
          <div className="login-form">
            <h2>Choose Your Password</h2>
            <p>
              Your account is using a temporary password set by an administrator.
              Choose your own password to continue.
            </p>

            <form onSubmit={handleSubmit}>
              <div className="form-group">
                <label htmlFor="current-password">Temporary Password</label>
                <PasswordFieldWithToggle
                  id="current-password"
                  name="current_password"
                  value={currentPassword}
                  onChange={(e) => { setCurrentPassword(e.target.value); setError(''); }}
                  autoComplete="current-password"
                  placeholder="The password you were given"
                  required
                />
              </div>

              <div className="form-group">
                <label htmlFor="new-password">New Password</label>
                <PasswordFieldWithToggle
                  id="new-password"
                  name="new_password"
                  value={newPassword}
                  onChange={(e) => { setNewPassword(e.target.value); setError(''); }}
                  placeholder="At least 8 characters"
                  required
                />
                <small className="pwd-rules">
                  Must be 8+ characters with upper and lower case, a number, and a symbol.
                </small>
              </div>
```

`.pwd-rules` does not exist yet. Add it to `frontend/src/admin/styles/login.css`, after the `.error` rule at line 271:

```css
.pwd-rules {
  display: block;
  margin-top: 0.35rem;
  font-size: 0.75rem;
  color: #6b7280;
}
```

Continuing the component:

```jsx

              <div className="form-group">
                <label htmlFor="confirm-password">Confirm New Password</label>
                <PasswordFieldWithToggle
                  id="confirm-password"
                  name="new_password_confirmation"
                  value={confirmation}
                  onChange={(e) => { setConfirmation(e.target.value); setError(''); }}
                  placeholder="Re-enter the new password"
                  required
                />
              </div>

              {/* `error`, not `error-message` — login.css:271 defines .error,
                  and ForgotPassword.jsx renders its errors the same way. */}
              {error && <p className="error">{error}</p>}

              <button type="submit" className="login-btn" disabled={loading}>
                {loading ? 'Saving…' : 'Set Password'}
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ChangePassword;
```

- [ ] **Step 2: Register the route**

In `frontend/src/App.jsx`, add the lazy import alongside the other page imports (match the surrounding `lazy(() => import(...))` style):

```jsx
const ChangePassword = lazy(() => import('./admin/pages/ChangePassword'));
```

Then add the route directly after line 93's forgot-password route. It sits outside `RoleRoute` on purpose — a flagged user must be able to reach it:

```jsx
          <Route path="/admin/forgot-password" element={<ForgotPassword />} />
          {/* Outside RoleRoute: the guard inside RoleRoute redirects here, so
              wrapping this route in it would loop. */}
          <Route path="/admin/change-password" element={<ChangePassword />} />
```

- [ ] **Step 3: Add the guard to RoleRoute**

In `frontend/src/admin/components/RoleRoute.jsx`, add one check immediately after the existing `if (!user)` line (line 12):

```jsx
  if (!user) return <Navigate to="/admin/login" replace />;

  // Temporary password still in place — every other API route returns 403, so
  // send the user to the one screen that can clear it. The middleware is the
  // real enforcement; this just avoids rendering pages that cannot load data.
  if (user.must_change_password) return <Navigate to="/admin/change-password" replace />;
```

- [ ] **Step 4: Handle the 403 in apiRequest**

In `frontend/src/admin/services/api.js`, inside the `if (!response.ok)` block, add a branch directly after the existing 401 handling (which ends at line 276):

```jsx
    // Server says the temporary password must be replaced. Can happen mid-session
    // if an admin resets this user's password while they are logged in.
    if (response.status === 403 && data.code === 'password_change_required') {
      if (typeof window !== 'undefined' && !window.location.pathname.includes('/change-password')) {
        window.location.href = '/admin/change-password';
      }
    }
```

- [ ] **Step 5: Fix the forgot-password copy**

In `frontend/src/admin/pages/ForgotPassword.jsx`, replace the step 1 instruction at line 135:

```jsx
                <p>Enter your account email. We will send a 6-digit password reset code.</p>
```

with:

```jsx
                <p>
                  Enter your account email. If an account exists, we'll send a
                  6-digit reset code to the recovery email on file.
                </p>
```

This wording matters: the backend now returns success for unknown emails, so a user who mistypes gets a success message and no mail. Without the conditional phrasing that behaviour reads as a bug.

- [ ] **Step 6: Verify the whole flow end to end**

Start both servers, then:

```bash
cd back-end && php artisan serve
```
```bash
cd frontend && npm start
```

1. As an admin, create an employee with a distinct recovery email.
2. Sign out. Sign in as that employee with the temporary password.
   Expected: immediately redirected to `/admin/change-password`; navigating manually to `/admin/dashboard` bounces straight back.
3. Submit a valid new password.
   Expected: toast, then the dashboard loads normally.
4. Sign out, click "Forgot Password", enter the employee's **login** email.
   Expected: success message. Confirm the code went to the recovery address:

```bash
cd back-end && grep -A 5 "To:" storage/logs/laravel.log | tail -20
```

   Expected: the `To:` header shows the recovery address, not the `@airking.com` login address.
5. Enter a nonexistent email.
   Expected: the same success message, and no new mail in the log.

- [ ] **Step 7: Run the frontend test suite for regressions**

```bash
cd frontend && npx react-scripts test --watchAll=false --testPathPattern="RoleRoute"
```

Expected: PASS. `RoleRoute.test.jsx` and `RoleRoute.permissionGated.test.jsx` build user fixtures without `must_change_password`, which is `undefined` and therefore falsy — the new guard should not fire. If any test does redirect to `/admin/change-password`, add `must_change_password: false` to that test's user fixture.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/ChangePassword.jsx frontend/src/admin/styles/login.css frontend/src/App.jsx frontend/src/admin/components/RoleRoute.jsx frontend/src/admin/services/api.js frontend/src/admin/pages/ForgotPassword.jsx
git commit -m "feat(auth): add forced change-password screen and client-side gate"
```

---

## Task 9: Full-suite regression check

**Files:** none — verification only.

- [ ] **Step 1: Run the entire backend suite**

```bash
cd back-end && php artisan test
```

Expected: PASS. The likeliest new failure is a pre-existing test whose user helper omits `must_change_password`, so the row takes the `true` default and the new middleware 403s an assertion expecting 200. Fix by adding `'must_change_password' => false` to that test's `User::create([...])` helper — not by weakening the middleware.

- [ ] **Step 2: Run the entire frontend suite**

```bash
cd frontend && npx react-scripts test --watchAll=false
```

Expected: PASS.

- [ ] **Step 3: Confirm the migration reverses cleanly**

```bash
cd back-end && php artisan migrate:rollback --step=1 --force && php artisan migrate --force
```

Expected: both columns drop and re-add without error.

`--force` is required because this machine's `back-end/.env` sets `APP_ENV=production`, so artisan prompts for confirmation on any destructive command and a non-interactive run aborts.

- [ ] **Step 4: Commit any test fixes**

```bash
git add -A back-end/tests frontend/src
git commit -m "test: align existing fixtures with the forced password change gate"
```

---

## Out of scope

Per the spec, untouched by this plan:

- The legacy `PasswordResetController` `RST-` admin-issued code flow and its routes.
- The login 2FA OTP in `AuthController` (`users.otp_code`) — a separate mechanism from forgot-password.
- The PWA / Supabase authentication path under `frontend/src/pwa`.
- `database/factories/UserFactory.php`, which is stale but unused by any test.
