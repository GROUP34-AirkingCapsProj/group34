# Self-Service Account Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every authenticated user a self-service Account Settings page to manage their own profile photo, personal/contact details, login email (local-part only — `@airking.com` is fixed), and password.

**Architecture:** A new auth-only `AccountController` exposing `/me/*` endpoints that always act on `$request->user()`, kept separate from the permission-gated admin `UserController`. A new React `AccountSettingsPage` (ak- design system) reachable from the header profile dropdown. Profile photos are stored on the Laravel `public` disk; the `User` model exposes a computed `avatar_url`.

**Tech Stack:** Laravel 11 (back-end), React 18 (frontend/src/admin), MariaDB via XAMPP, Sanctum auth, PHPUnit feature tests with `DatabaseTransactions` + `Storage::fake`.

**Spec:** `docs/superpowers/specs/2026-06-28-account-settings-design.md`

**Conventions to follow:**
- Run all `php artisan` / `composer` / test commands from the `back-end/` directory.
- Run all `npm` commands from the `frontend/` directory.
- Controllers use the `App\Traits\ApiResponse` trait: `$this->success($data, $msg, $code)`, `$this->error($msg, $code)`, `$this->validationError($errors)`. Success shape is `{ success, message, data }`.
- Feature tests live under `back-end/tests/Feature/...`, use `Tests\TestCase`, `use DatabaseTransactions;`, and authenticate with `$this->actingAs($user, 'sanctum')`. Create users via `User::create([... 'password_hash' => bcrypt('...'), 'role_id' => 1])` with a `uniqid()` suffix on username/email (see `tests/Feature/Alerts/MarkAllReadTest.php`).
- Frontend user state is managed by `useAuth()` (`frontend/src/admin/context/AuthContext.jsx`): use `updateUser(obj)` to update the cached session user and `refreshUser()` to re-fetch from `/api/user`.

---

## File Structure

**Back-end:**
- Create: `back-end/database/migrations/2026_06_28_120000_add_avatar_and_address_to_users.php` — adds `avatar_path`, `address` to `users`.
- Modify: `back-end/app/Models/User.php` — add fillable fields + `avatar_url` accessor + `$appends`.
- Create: `back-end/app/Http/Controllers/Api/AccountController.php` — all `/me/*` self-service actions.
- Modify: `back-end/routes/api.php` — register `/me/*` routes (after the `/me/permissions` line).
- Create: `back-end/tests/Feature/Account/AccountProfileTest.php` — profile + address tests.
- Create: `back-end/tests/Feature/Account/AccountAvatarTest.php` — avatar upload/delete tests.
- Create: `back-end/tests/Feature/Account/AccountEmailTest.php` — email local-part / domain-lock tests.
- Create: `back-end/tests/Feature/Account/AccountPasswordTest.php` — password change tests.

**Front-end:**
- Modify: `frontend/src/admin/services/api.js` — add `uploadRequest` helper + `accountAPI`.
- Create: `frontend/src/admin/pages/AccountSettingsPage.jsx` — the settings page.
- Modify: `frontend/src/App.jsx` — lazy import + route `/admin/account`.
- Modify: `frontend/src/admin/components/AdminLayout.jsx` — "Account Settings" link in profile dropdown + render avatar photo when present.

---

## Task 1: Database migration + User model

**Files:**
- Create: `back-end/database/migrations/2026_06_28_120000_add_avatar_and_address_to_users.php`
- Modify: `back-end/app/Models/User.php:32-45` (fillable), `:46-55` (casts/appends area)
- Test: `back-end/tests/Feature/Account/AccountProfileTest.php`

- [ ] **Step 1: Write the migration**

Create `back-end/database/migrations/2026_06_28_120000_add_avatar_and_address_to_users.php`:

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
            $table->string('avatar_path', 255)->nullable()->after('phone');
            $table->string('address', 255)->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'address']);
        });
    }
};
```

- [ ] **Step 2: Apply the migration**

Run (from `back-end/`): `php artisan migrate --path=database/migrations/2026_06_28_120000_add_avatar_and_address_to_users.php`
Expected: `INFO  Running migrations.` then a line ending in `DONE`.

- [ ] **Step 3: Update the User model**

In `back-end/app/Models/User.php`, add `avatar_path` and `address` to `$fillable` (the array starting at line 32), so it includes:

```php
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'username',
        'password_hash',
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

Add the `Storage` facade import near the other `use` statements at the top of the file:

```php
use Illuminate\Support\Facades\Storage;
```

Add an `$appends` property and an accessor. Place `$appends` right after the `$casts` array (after line 55) and the accessor method below `getAuthPassword()`:

```php
    protected $appends = ['avatar_url'];

    /**
     * Public URL for the user's avatar, or null when none is set.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }
```

- [ ] **Step 4: Write a failing test that the column + accessor work**

Create `back-end/tests/Feature/Account/AccountProfileTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();
        return User::create(array_merge([
            'first_name'    => 'Acc',
            'last_name'     => 'User',
            'username'      => 'acc_' . $uid,
            'email'         => 'acc_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ], $overrides));
    }

    public function test_avatar_url_is_null_when_no_avatar_set(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_address_column_persists(): void
    {
        $user = $this->makeUser(['address' => '123 Cooling St']);
        $this->assertSame('123 Cooling St', $user->fresh()->address);
    }
}
```

- [ ] **Step 5: Run the tests**

Run (from `back-end/`): `php artisan test --filter=AccountProfileTest`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_06_28_120000_add_avatar_and_address_to_users.php back-end/app/Models/User.php back-end/tests/Feature/Account/AccountProfileTest.php
git commit -m "feat(account): add avatar_path + address columns and avatar_url accessor"
```

---

## Task 2: AccountController — profile read/update + routes

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AccountController.php`
- Modify: `back-end/routes/api.php:148` (after the `/me/permissions` route)
- Test: `back-end/tests/Feature/Account/AccountProfileTest.php`

- [ ] **Step 1: Write failing tests for GET/PUT profile**

Append these methods to `back-end/tests/Feature/Account/AccountProfileTest.php` (inside the class):

```php
    public function test_show_returns_own_profile_with_email_local_split(): void
    {
        $user = $this->makeUser(['email' => 'jdela.cruz@airking.com']);

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/me/profile');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.email', 'jdela.cruz@airking.com');
        $resp->assertJsonPath('data.email_local', 'jdela.cruz');
        $resp->assertJsonPath('data.email_domain', '@airking.com');
    }

    public function test_update_persists_name_phone_address(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'New',
            'last_name'  => 'Name',
            'phone'      => '09171234567',
            'address'    => '5th Ave, Makati',
        ]);

        $resp->assertStatus(200);
        $fresh = $user->fresh();
        $this->assertSame('New', $fresh->first_name);
        $this->assertSame('Name', $fresh->last_name);
        $this->assertSame('09171234567', $fresh->phone);
        $this->assertSame('5th Ave, Makati', $fresh->address);
    }

    public function test_update_does_not_change_email_or_role(): void
    {
        $user = $this->makeUser(['role_id' => 1]);
        $originalEmail = $user->email;

        $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'X',
            'last_name'  => 'Y',
            'email'      => 'hacker@evil.com',
            'role_id'    => 99,
        ])->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame($originalEmail, $fresh->email);
        $this->assertSame(1, (int) $fresh->role_id);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run (from `back-end/`): `php artisan test --filter=AccountProfileTest`
Expected: FAIL — the three new tests 404 (route/controller not defined yet).

- [ ] **Step 3: Create the controller with show() + update()**

Create `back-end/app/Http/Controllers/Api/AccountController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    use ApiResponse;

    /** The single fixed email domain. The local-part is user-editable; this is not. */
    private const EMAIL_DOMAIN = '@airking.com';

    /**
     * Serialize the authenticated user for the settings page, splitting email
     * into its editable local-part and the fixed domain.
     */
    private function presentProfile($user): array
    {
        $email = (string) $user->email;
        $atPos = strrpos($email, '@');
        $local = $atPos === false ? $email : substr($email, 0, $atPos);

        return array_merge($user->load(['role', 'status', 'location'])->toArray(), [
            'email_local'  => $local,
            'email_domain' => self::EMAIL_DOMAIN,
        ]);
    }

    public function show(Request $request)
    {
        return $this->success($this->presentProfile($request->user()), 'Profile retrieved');
    }

    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:50',
                'last_name'  => 'required|string|max:50',
                'phone'      => 'nullable|string|max:20',
                'address'    => 'nullable|string|max:255',
            ]);

            $user = $request->user();
            $user->update($validated);

            return $this->success($this->presentProfile($user->fresh()), 'Profile updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }
}
```

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, add the `AccountController` import near the other `Api` controller imports at the top (e.g. below the `UserController` import on line 7):

```php
use App\Http\Controllers\Api\AccountController;
```

Then, immediately after the `/me/permissions` route (line 148, inside the same authenticated group), add:

```php
    // Self-service account settings (any authenticated user, own account only)
    Route::get('/me/profile', [AccountController::class, 'show']);
    Route::put('/me/profile', [AccountController::class, 'update']);
    Route::post('/me/avatar', [AccountController::class, 'uploadAvatar']);
    Route::delete('/me/avatar', [AccountController::class, 'deleteAvatar']);
    Route::put('/me/email', [AccountController::class, 'updateEmail']);
    Route::put('/me/password', [AccountController::class, 'updatePassword']);
```

(The avatar/email/password handlers are added in later tasks; declaring the routes now is harmless because the tests for those tasks come with their handlers.)

- [ ] **Step 5: Run the profile tests**

Run (from `back-end/`): `php artisan test --filter=AccountProfileTest`
Expected: PASS (5 passed). If you get a "Method ... does not exist" error for avatar/email/password routes, ignore — those routes are only exercised in later tasks. The `AccountProfileTest` cases must pass.

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AccountController.php back-end/routes/api.php back-end/tests/Feature/Account/AccountProfileTest.php
git commit -m "feat(account): GET/PUT /me/profile self-service endpoints"
```

---

## Task 3: Avatar upload + delete

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AccountController.php` (add `uploadAvatar`, `deleteAvatar`)
- Test: `back-end/tests/Feature/Account/AccountAvatarTest.php`

- [ ] **Step 1: Ensure the public disk symlink exists (one-time)**

Run (from `back-end/`): `php artisan storage:link`
Expected: either `INFO  The [public/storage] link has been connected...` or `The "public/storage" link already exists.` Either is fine.

- [ ] **Step 2: Write failing avatar tests**

Create `back-end/tests/Feature/Account/AccountAvatarTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountAvatarTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Ava',
            'last_name'     => 'Tar',
            'username'      => 'ava_' . $uid,
            'email'         => 'ava_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    public function test_upload_stores_image_and_sets_avatar_path(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ]);

        $resp->assertStatus(200);
        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($resp->json('data.avatar_url'));
    }

    public function test_upload_rejects_non_image(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ]);

        $resp->assertStatus(422);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_upload_rejects_oversized_image(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        // 3 MB — over the 2 MB (2048 KB) limit.
        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('big.jpg')->size(3072),
        ]);

        $resp->assertStatus(422);
    }

    public function test_replacing_avatar_deletes_old_file(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertStatus(200);
        $firstPath = $user->fresh()->avatar_path;

        $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->assertStatus(200);
        $secondPath = $user->fresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_delete_removes_avatar(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ])->assertStatus(200);
        $path = $user->fresh()->avatar_path;

        $resp = $this->actingAs($user, 'sanctum')->deleteJson('/api/me/avatar');

        $resp->assertStatus(200);
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run (from `back-end/`): `php artisan test --filter=AccountAvatarTest`
Expected: FAIL (handlers not implemented; routes return errors).

- [ ] **Step 4: Implement uploadAvatar() + deleteAvatar()**

Add these methods to `AccountController` (in `back-end/app/Http/Controllers/Api/AccountController.php`):

```php
    public function uploadAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
            ]);

            $user = $request->user();
            $oldPath = $user->avatar_path;

            // Store the new file first; only delete the old one once this succeeds.
            $newPath = $request->file('avatar')->store('avatars', 'public');

            $user->update(['avatar_path' => $newPath]);

            if ($oldPath && $oldPath !== $newPath) {
                Storage::disk('public')->delete($oldPath);
            }

            return $this->success($this->presentProfile($user->fresh()), 'Avatar updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return $this->success($this->presentProfile($user->fresh()), 'Avatar removed');
    }
```

- [ ] **Step 5: Run the avatar tests**

Run (from `back-end/`): `php artisan test --filter=AccountAvatarTest`
Expected: PASS (5 passed).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AccountController.php back-end/tests/Feature/Account/AccountAvatarTest.php
git commit -m "feat(account): avatar upload + delete endpoints"
```

---

## Task 4: Email change (local-part only, domain locked)

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AccountController.php` (add `updateEmail`)
- Test: `back-end/tests/Feature/Account/AccountEmailTest.php`

- [ ] **Step 1: Write failing email tests**

Create `back-end/tests/Feature/Account/AccountEmailTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $local = null, string $password = 'Secret123!'): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Em',
            'last_name'     => 'Ail',
            'username'      => 'em_' . $uid,
            'email'         => ($local ?? 'em_' . $uid) . '@airking.com',
            'password_hash' => bcrypt($password),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    public function test_changes_local_part_and_forces_domain(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'new.handle',
            'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(200);
        $this->assertSame('new.handle@airking.com', $user->fresh()->email);
    }

    public function test_domain_in_submitted_local_part_is_ignored(): void
    {
        $user = $this->makeUser();

        // Even if the user tries to smuggle a domain in, the server forces @airking.com.
        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'evil@gmail.com',
            'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(422); // '@' is not a valid local-part character here
        $this->assertStringEndsWith('@airking.com', $user->fresh()->email);
    }

    public function test_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();
        $original = $user->email;

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'whatever',
            'current_password' => 'WrongPass1!',
        ]);

        $resp->assertStatus(422);
        $this->assertSame($original, $user->fresh()->email);
    }

    public function test_rejects_duplicate_email(): void
    {
        $taken = $this->makeUser('taken.handle');
        $user  = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'taken.handle',
            'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run (from `back-end/`): `php artisan test --filter=AccountEmailTest`
Expected: FAIL (handler not implemented).

- [ ] **Step 3: Implement updateEmail()**

Add to `AccountController`:

```php
    public function updateEmail(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                // Local-part only: letters, digits, dot, underscore, %, +, -. No '@'.
                'email_local'      => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._%+\-]+$/'],
                'current_password' => ['required', 'string'],
            ]);

            if (! Hash::check($validated['current_password'], $user->password_hash)) {
                return $this->error('Current password is incorrect', 422);
            }

            $newEmail = $validated['email_local'] . self::EMAIL_DOMAIN;

            $exists = \App\Models\User::where('email', $newEmail)
                ->where('user_id', '!=', $user->user_id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'email_local' => ['That email address is already taken.'],
                ]);
            }

            $user->update(['email' => $newEmail]);

            return $this->success($this->presentProfile($user->fresh()), 'Email updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }
```

- [ ] **Step 4: Run the email tests**

Run (from `back-end/`): `php artisan test --filter=AccountEmailTest`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AccountController.php back-end/tests/Feature/Account/AccountEmailTest.php
git commit -m "feat(account): change email local-part with locked @airking.com domain"
```

---

## Task 5: Password change

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AccountController.php` (add `updatePassword`)
- Test: `back-end/tests/Feature/Account/AccountPasswordTest.php`

- [ ] **Step 1: Write failing password tests**

Create `back-end/tests/Feature/Account/AccountPasswordTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $password = 'Secret123!'): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Pw',
            'last_name'     => 'User',
            'username'      => 'pw_' . $uid,
            'email'         => 'pw_' . $uid . '@airking.com',
            'password_hash' => bcrypt($password),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    public function test_changes_password_with_correct_current(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password'          => 'Secret123!',
            'new_password'              => 'BrandNew1!',
            'new_password_confirmation' => 'BrandNew1!',
        ]);

        $resp->assertStatus(200);
        $this->assertTrue(Hash::check('BrandNew1!', $user->fresh()->password_hash));
    }

    public function test_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password'          => 'WrongPass1!',
            'new_password'              => 'BrandNew1!',
            'new_password_confirmation' => 'BrandNew1!',
        ]);

        $resp->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123!', $user->fresh()->password_hash));
    }

    public function test_rejects_weak_new_password(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password'          => 'Secret123!',
            'new_password'              => 'weak',
            'new_password_confirmation' => 'weak',
        ]);

        $resp->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run (from `back-end/`): `php artisan test --filter=AccountPasswordTest`
Expected: FAIL (handler not implemented).

- [ ] **Step 3: Implement updatePassword()**

Add to `AccountController`:

```php
    public function updatePassword(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'new_password'     => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            ]);

            if (! Hash::check($validated['current_password'], $user->password_hash)) {
                return $this->error('Current password is incorrect', 422);
            }

            $user->update(['password_hash' => Hash::make($validated['new_password'])]);

            return $this->success(null, 'Password changed successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }
```

- [ ] **Step 4: Run the password tests + the whole Account suite**

Run (from `back-end/`): `php artisan test --filter=AccountPasswordTest`
Expected: PASS (3 passed).

Then run the full group: `php artisan test tests/Feature/Account`
Expected: PASS (all Account tests green — 19 total across the four files).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AccountController.php back-end/tests/Feature/Account/AccountPasswordTest.php
git commit -m "feat(account): change own password with current-password re-check"
```

---

## Task 6: Frontend API client — upload helper + accountAPI

**Files:**
- Modify: `frontend/src/admin/services/api.js` (add `uploadRequest` near `apiRequest`; add `accountAPI` near `companySettingsAPI`)

- [ ] **Step 1: Add an upload-aware request helper**

In `frontend/src/admin/services/api.js`, immediately after the `apiRequest` function (it ends around line 200 with its closing `};`), add a sibling helper that sends `FormData` without forcing a JSON content-type (the browser sets the multipart boundary):

```js
/* Upload helper: sends FormData; never sets Content-Type so the browser can
   add the multipart boundary. Mirrors apiRequest's auth + error handling. */
const uploadRequest = async (endpoint, formData, method = 'POST') => {
  const token = getToken();
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;

  const base = getApiBaseUrl();
  const url = endpoint.startsWith('/') ? `${base}${endpoint}` : `${base}/${endpoint}`;

  const response = await fetch(url, { method, headers, body: formData });

  let data;
  try {
    const text = await response.text();
    data = text ? JSON.parse(text) : { message: 'Server error.' };
  } catch (_) {
    throw new Error(`Server returned ${response.status} with non-JSON response.`);
  }

  if (!response.ok) {
    const err = new Error(data.message || 'Request failed');
    err.errors = data.errors;
    err.status = response.status;
    throw err;
  }
  return data;
};
```

- [ ] **Step 2: Add the accountAPI object**

In the same file, immediately after the `companySettingsAPI` block (ends around line 393), add:

```js
/* ======================
   ACCOUNT (SELF-SERVICE) API
====================== */
export const accountAPI = {
  getProfile: async () => apiRequest('/me/profile'),
  updateProfile: async (data) =>
    apiRequest('/me/profile', { method: 'PUT', body: JSON.stringify(data) }),
  uploadAvatar: async (file) => {
    const fd = new FormData();
    fd.append('avatar', file);
    return uploadRequest('/me/avatar', fd, 'POST');
  },
  deleteAvatar: async () => apiRequest('/me/avatar', { method: 'DELETE' }),
  updateEmail: async (data) =>
    apiRequest('/me/email', { method: 'PUT', body: JSON.stringify(data) }),
  updatePassword: async (data) =>
    apiRequest('/me/password', { method: 'PUT', body: JSON.stringify(data) }),
};
```

- [ ] **Step 3: Verify the bundle builds**

Run (from `frontend/`): `npm run build`
Expected: build completes without errors referencing `api.js`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(account): frontend accountAPI client + FormData upload helper"
```

---

## Task 7: AccountSettingsPage + route

**Files:**
- Create: `frontend/src/admin/pages/AccountSettingsPage.jsx`
- Modify: `frontend/src/App.jsx` (lazy import + route)

- [ ] **Step 1: Create the page**

Create `frontend/src/admin/pages/AccountSettingsPage.jsx`:

```jsx
import React, { useState, useEffect, useRef } from 'react';
import AdminLayout from '../components/AdminLayout';
import Loader from '../components/Loader';
import { useAuth } from '../context/AuthContext';
import { accountAPI } from '../services/api';
import '../styles/airking_ds.css';

const EMAIL_DOMAIN = '@airking.com';

const Banner = ({ kind, children }) => {
  if (!children) return null;
  const ok = kind === 'success';
  return (
    <div style={{
      background: ok ? '#f0fdf4' : '#fef2f2',
      border: `1px solid ${ok ? '#bbf7d0' : '#fecaca'}`,
      borderRadius: 8, padding: '10px 16px', marginBottom: 16,
      fontSize: 13, color: ok ? '#15803d' : '#b91c1c',
    }}>{children}</div>
  );
};

const AccountSettingsPage = () => {
  const { user, updateUser, refreshUser } = useAuth();
  const fileRef = useRef(null);

  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState(null);

  // Section forms
  const [info, setInfo] = useState({ first_name: '', last_name: '', phone: '', address: '' });
  const [emailLocal, setEmailLocal] = useState('');
  const [emailPw, setEmailPw] = useState('');
  const [pw, setPw] = useState({ current_password: '', new_password: '', new_password_confirmation: '' });

  // Per-section feedback
  const [msg, setMsg] = useState({}); // { info?: {kind,text}, email?:..., password?:..., avatar?:... }
  const [busy, setBusy] = useState({});

  const setSectionMsg = (section, kind, text) =>
    setMsg((m) => ({ ...m, [section]: { kind, text } }));

  const load = async () => {
    try {
      const res = await accountAPI.getProfile();
      const d = res.data;
      setProfile(d);
      setInfo({
        first_name: d.first_name || '',
        last_name:  d.last_name  || '',
        phone:      d.phone      || '',
        address:    d.address    || '',
      });
      setEmailLocal(d.email_local || '');
    } catch {
      setSectionMsg('info', 'error', 'Failed to load your account.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  // After any change that affects the cached session user (name/email/avatar),
  // re-fetch so the header/sidebar reflect it immediately.
  const syncSession = async (data) => {
    if (data) updateUser({ ...user, ...data });
    refreshUser();
  };

  const saveInfo = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, info: true }));
    setSectionMsg('info', null, '');
    try {
      const res = await accountAPI.updateProfile(info);
      setProfile(res.data);
      await syncSession({
        first_name: res.data.first_name,
        last_name:  res.data.last_name,
        phone:      res.data.phone,
        address:    res.data.address,
      });
      setSectionMsg('info', 'success', 'Profile saved.');
    } catch (err) {
      setSectionMsg('info', 'error', err.message || 'Could not save profile.');
    } finally {
      setBusy((b) => ({ ...b, info: false }));
    }
  };

  const onPickFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setBusy((b) => ({ ...b, avatar: true }));
    setSectionMsg('avatar', null, '');
    try {
      const res = await accountAPI.uploadAvatar(file);
      setProfile(res.data);
      await syncSession({ avatar_url: res.data.avatar_url, avatar_path: res.data.avatar_path });
      setSectionMsg('avatar', 'success', 'Photo updated.');
    } catch (err) {
      setSectionMsg('avatar', 'error', err.message || 'Upload failed.');
    } finally {
      setBusy((b) => ({ ...b, avatar: false }));
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const removeAvatar = async () => {
    setBusy((b) => ({ ...b, avatar: true }));
    setSectionMsg('avatar', null, '');
    try {
      const res = await accountAPI.deleteAvatar();
      setProfile(res.data);
      await syncSession({ avatar_url: null, avatar_path: null });
      setSectionMsg('avatar', 'success', 'Photo removed.');
    } catch (err) {
      setSectionMsg('avatar', 'error', err.message || 'Could not remove photo.');
    } finally {
      setBusy((b) => ({ ...b, avatar: false }));
    }
  };

  const saveEmail = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, email: true }));
    setSectionMsg('email', null, '');
    try {
      const res = await accountAPI.updateEmail({ email_local: emailLocal, current_password: emailPw });
      setProfile(res.data);
      setEmailPw('');
      await syncSession({ email: res.data.email });
      setSectionMsg('email', 'success', 'Email updated.');
    } catch (err) {
      const detail = err.errors?.email_local?.[0] || err.message;
      setSectionMsg('email', 'error', detail || 'Could not update email.');
    } finally {
      setBusy((b) => ({ ...b, email: false }));
    }
  };

  const savePassword = async (e) => {
    e.preventDefault();
    setBusy((b) => ({ ...b, password: true }));
    setSectionMsg('password', null, '');
    try {
      await accountAPI.updatePassword(pw);
      setPw({ current_password: '', new_password: '', new_password_confirmation: '' });
      setSectionMsg('password', 'success', 'Password changed.');
    } catch (err) {
      const detail = err.errors?.new_password?.[0] || err.message;
      setSectionMsg('password', 'error', detail || 'Could not change password.');
    } finally {
      setBusy((b) => ({ ...b, password: false }));
    }
  };

  if (loading) {
    return <AdminLayout><Loader variant="page" /></AdminLayout>;
  }

  const initials =
    (info.first_name && info.last_name)
      ? (info.first_name.charAt(0) + info.last_name.charAt(0)).toUpperCase()
      : (profile?.username?.charAt(0)?.toUpperCase() || 'U');

  const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: 20, marginBottom: 20 };
  const label = { display: 'block', fontSize: 12, fontWeight: 600, color: '#374151', marginBottom: 6 };
  const input = { width: '100%', padding: '8px 12px', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, marginBottom: 14 };
  const btn = { padding: '8px 16px', background: '#2563eb', color: '#fff', border: 'none', borderRadius: 8, fontSize: 14, fontWeight: 600, cursor: 'pointer' };

  return (
    <AdminLayout>
      <div className="ak-page" style={{ maxWidth: 640, margin: '0 auto', padding: '24px 16px' }}>
        <header className="ak-header">
          <div className="ak-header-row">
            <div>
              <p className="ak-eyebrow">Account</p>
              <h1 className="ak-title">Account Settings</h1>
              <p className="ak-sub">Manage your photo, contact details, email, and password.</p>
            </div>
          </div>
        </header>

        {/* Profile photo */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Profile photo</h2>
          <Banner kind={msg.avatar?.kind}>{msg.avatar?.text}</Banner>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{
              width: 72, height: 72, borderRadius: '50%', overflow: 'hidden',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              background: '#2563eb', color: '#fff', fontSize: 24, fontWeight: 700,
            }}>
              {profile?.avatar_url
                ? <img src={profile.avatar_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                : initials}
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <input ref={fileRef} type="file" accept="image/*" onChange={onPickFile} style={{ display: 'none' }} />
              <button type="button" style={btn} disabled={busy.avatar} onClick={() => fileRef.current?.click()}>
                {busy.avatar ? 'Uploading…' : 'Upload'}
              </button>
              {profile?.avatar_url && (
                <button type="button" style={{ ...btn, background: '#6b7280' }} disabled={busy.avatar} onClick={removeAvatar}>
                  Remove
                </button>
              )}
            </div>
          </div>
        </section>

        {/* Personal info */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Personal information</h2>
          <Banner kind={msg.info?.kind}>{msg.info?.text}</Banner>
          <form onSubmit={saveInfo}>
            <label style={label}>First name</label>
            <input style={input} value={info.first_name} onChange={(e) => setInfo({ ...info, first_name: e.target.value })} required />
            <label style={label}>Last name</label>
            <input style={input} value={info.last_name} onChange={(e) => setInfo({ ...info, last_name: e.target.value })} required />
            <label style={label}>Phone</label>
            <input style={input} value={info.phone} onChange={(e) => setInfo({ ...info, phone: e.target.value })} />
            <label style={label}>Address</label>
            <input style={input} value={info.address} onChange={(e) => setInfo({ ...info, address: e.target.value })} />
            <button type="submit" style={btn} disabled={busy.info}>{busy.info ? 'Saving…' : 'Save changes'}</button>
          </form>
        </section>

        {/* Email */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Email address</h2>
          <Banner kind={msg.email?.kind}>{msg.email?.text}</Banner>
          <form onSubmit={saveEmail}>
            <label style={label}>Email</label>
            <div style={{ display: 'flex', alignItems: 'stretch', marginBottom: 14 }}>
              <input
                style={{ ...input, marginBottom: 0, borderTopRightRadius: 0, borderBottomRightRadius: 0 }}
                value={emailLocal}
                onChange={(e) => setEmailLocal(e.target.value)}
                required
              />
              <span style={{
                display: 'inline-flex', alignItems: 'center', padding: '0 12px',
                background: '#f3f4f6', border: '1px solid #d1d5db', borderLeft: 'none',
                borderTopRightRadius: 8, borderBottomRightRadius: 8, fontSize: 14, color: '#6b7280',
              }}>{EMAIL_DOMAIN}</span>
            </div>
            <label style={label}>Current password</label>
            <input style={input} type="password" value={emailPw} onChange={(e) => setEmailPw(e.target.value)} required />
            <button type="submit" style={btn} disabled={busy.email}>{busy.email ? 'Saving…' : 'Update email'}</button>
          </form>
        </section>

        {/* Password */}
        <section style={card}>
          <h2 style={{ fontSize: 15, fontWeight: 700, marginBottom: 14 }}>Password</h2>
          <Banner kind={msg.password?.kind}>{msg.password?.text}</Banner>
          <form onSubmit={savePassword}>
            <label style={label}>Current password</label>
            <input style={input} type="password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} required />
            <label style={label}>New password</label>
            <input style={input} type="password" value={pw.new_password} onChange={(e) => setPw({ ...pw, new_password: e.target.value })} required />
            <label style={label}>Confirm new password</label>
            <input style={input} type="password" value={pw.new_password_confirmation} onChange={(e) => setPw({ ...pw, new_password_confirmation: e.target.value })} required />
            <button type="submit" style={btn} disabled={busy.password}>{busy.password ? 'Saving…' : 'Change password'}</button>
          </form>
        </section>
      </div>
    </AdminLayout>
  );
};

export default AccountSettingsPage;
```

- [ ] **Step 2: Register the route**

In `frontend/src/App.jsx`, add a lazy import near the other page imports (e.g. after the `ArchiveSettingsPage` import on line 55):

```jsx
const AccountSettingsPage = lazy(() => import('./admin/pages/AccountSettingsPage'));
```

Then add a route inside `<Routes>` (e.g. right after the dashboard route on line 109):

```jsx
          <Route path="/admin/account" element={<RoleRoute><AccountSettingsPage /></RoleRoute>} />
```

- [ ] **Step 3: Verify the build**

Run (from `frontend/`): `npm run build`
Expected: build completes without errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/pages/AccountSettingsPage.jsx frontend/src/App.jsx
git commit -m "feat(account): AccountSettingsPage with photo/info/email/password sections"
```

---

## Task 8: Entry point + avatar photo in header

**Files:**
- Modify: `frontend/src/admin/components/AdminLayout.jsx` (profile dropdown link + avatar `<img>`)

- [ ] **Step 1: Add an "Account Settings" link to the profile dropdown**

In `frontend/src/admin/components/AdminLayout.jsx`, the profile dropdown's actions are around line 422 (the `hpd-logout` button). Add a settings link directly above that `<button className="hpd-logout" ...>`:

```jsx
                  <button
                    className="hpd-logout"
                    style={{ marginBottom: 8 }}
                    onClick={() => { setProfileOpen(false); navigate('/admin/account'); }}
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <circle cx="12" cy="12" r="3" />
                      <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                    </svg>
                    Account Settings
                  </button>
```

Confirm `navigate` is available in this component. `AdminLayout.jsx` already imports from `react-router-dom`; if `navigate` is not already destructured, add near the top of the component body:

```jsx
  const navigate = useNavigate();
```

and ensure `useNavigate` is in the `react-router-dom` import line. (Search the file for `useNavigate` first — it is very likely already imported/used.)

- [ ] **Step 2: Render the uploaded photo in the header avatar**

In the header avatar block (around line 360-371), replace the inner initials `<span>` so it shows the photo when present. Change:

```jsx
              <div
                className="header-user-avatar"
                style={{ background: avatarColor(user.first_name || user.username || 'S'), color: '#fff' }}
              >
                <span style={{ fontSize: 13, fontWeight: 700, lineHeight: 1 }}>
                  {user.first_name && user.last_name
                    ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                    : user.username
                    ? user.username.charAt(0).toUpperCase()
                    : 'S'}
                </span>
              </div>
```

to:

```jsx
              <div
                className="header-user-avatar"
                style={{ background: avatarColor(user.first_name || user.username || 'S'), color: '#fff', overflow: 'hidden' }}
              >
                {user.avatar_url ? (
                  <img src={user.avatar_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                ) : (
                  <span style={{ fontSize: 13, fontWeight: 700, lineHeight: 1 }}>
                    {user.first_name && user.last_name
                      ? user.first_name.charAt(0).toUpperCase() + user.last_name.charAt(0).toUpperCase()
                      : user.username
                      ? user.username.charAt(0).toUpperCase()
                      : 'S'}
                  </span>
                )}
              </div>
```

- [ ] **Step 3: Verify the build**

Run (from `frontend/`): `npm run build`
Expected: build completes without errors.

- [ ] **Step 4: Manual smoke test**

Start the back-end (`php artisan serve` from `back-end/`) and frontend (`npm start` from `frontend/`). Log in, open the header profile dropdown → click **Account Settings**. Verify you can:
1. Upload a photo (header avatar updates after save).
2. Edit name/phone/address and save.
3. Change the email local-part (suffix stays locked `@airking.com`) with current password.
4. Change the password with current password.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/AdminLayout.jsx
git commit -m "feat(account): header dropdown link + avatar photo rendering"
```

---

## Self-Review Notes

- **Spec coverage:** avatar upload/replace/delete (Task 3) ✓; name/phone/address (Tasks 1,2,7) ✓; email local-part with forced `@airking.com` + uniqueness + current-password (Task 4) ✓; password change with current-password (Task 5) ✓; `avatar_url` in API + header (Tasks 1,8) ✓; self-only authorization — all handlers act on `$request->user()`, never a route id ✓; tests for every endpoint (Tasks 1-5) ✓.
- **Domain lock guarantee:** the server composes `email_local . '@airking.com'` and the `regex` rule forbids `@` in `email_local`, so the domain is structurally unchangeable (Task 4 `test_domain_in_submitted_local_part_is_ignored`).
- **Naming consistency:** controller methods `show/update/uploadAvatar/deleteAvatar/updateEmail/updatePassword` match the route registrations in Task 2 and the `accountAPI` methods in Task 6.
- **Session sync:** the page uses `useAuth().updateUser` + `refreshUser` (per AuthContext) so the header reflects new name/photo/email immediately.
```
