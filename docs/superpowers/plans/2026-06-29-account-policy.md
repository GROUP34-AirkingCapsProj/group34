# Admin-Configurable Account-Settings Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin globally control which fields (profile picture, email, phone, address) non-admin users may edit on their own Account Settings page, enforced on the backend and reflected in the UI.

**Architecture:** Store the policy as one JSON blob in the existing `SystemSetting` key/value store with typed accessors. A small admin-only `AccountPolicyController` reads/writes it. `AccountController` computes an *effective* policy per user (admins bypass — always all-true) and enforces it on `/me/*` writes, and reports it in `/me/profile`. The System Config "Profile Settings" tab is repurposed into a "User Accounts" policy editor; the Account Settings page disables locked fields with a note.

**Tech Stack:** Laravel 11 (back-end), React 18 (frontend/src/admin), MariaDB, Sanctum, PHPUnit with `DatabaseTransactions`.

**Spec:** `docs/superpowers/specs/2026-06-29-account-policy-design.md`

**Conventions:**
- Run `php artisan` / tests from `back-end/`; `npm` from `frontend/`; `git` from repo root `C:\XAMPP1\htdocs\Airking_Inventory`. Shell is PowerShell — chain with `;`.
- Controllers use `App\Traits\ApiResponse` (`$this->success`, `$this->error`, `$this->validationError`, `$this->forbidden`). System-settings endpoints (`SystemSessionSettingsController`, `CompanySettingsController`) return a **plain `response()->json(...)`** (no `success` envelope) — match that for `AccountPolicyController` so the frontend reads fields directly.
- Feature tests: `Tests\TestCase`, `use DatabaseTransactions;`, `actingAs($user, 'sanctum')`, users via `User::create([... 'password_hash'=>bcrypt('x'), 'role_id'=>N])` with a `uniqid()` suffix.
- Admin = `role_id === 1`. Other seeded roles: inventory_analyst=2, branch_manager=3, warehouse_personnel=4, auditor=5.

---

## File Structure

**Back-end:**
- Modify: `back-end/app/Models/SystemSetting.php` — add `KEY_ACCOUNT_POLICY`, `accountPolicy()`, `setAccountPolicy()`.
- Create: `back-end/app/Http/Controllers/Api/AccountPolicyController.php` — admin GET/PUT policy.
- Modify: `back-end/routes/api.php` — import + two admin-only routes.
- Modify: `back-end/app/Http/Controllers/Api/AccountController.php` — `effectivePolicy()` helper, enforcement in `uploadAvatar`/`deleteAvatar`/`updateEmail`/`update`, `policy` in `presentProfile`.
- Create: `back-end/tests/Feature/Account/AccountPolicyTest.php` — model + endpoint tests.
- Create: `back-end/tests/Feature/Account/AccountPolicyEnforcementTest.php` — `/me/*` enforcement tests.

**Front-end:**
- Modify: `frontend/src/admin/services/api.js` — add `accountPolicyAPI`.
- Modify: `frontend/src/admin/pages/SystemConfiguration.jsx` — repurpose "Profile Settings" → "User Accounts" policy editor; remove the admin-profile stub.
- Modify: `frontend/src/admin/pages/AccountSettingsPage.jsx` — disable locked fields, show managed note.
- Modify: `frontend/src/admin/styles/account_settings.css` — `.as-managed` + disabled-input styling.

---

## Task 1: SystemSetting policy accessors

**Files:**
- Modify: `back-end/app/Models/SystemSetting.php`
- Test: `back-end/tests/Feature/Account/AccountPolicyTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Account/AccountPolicyTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountPolicyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_account_policy_defaults_to_all_true(): void
    {
        $policy = SystemSetting::accountPolicy();
        $this->assertSame(
            ['avatar' => true, 'email' => true, 'phone' => true, 'address' => true],
            $policy
        );
    }

    public function test_set_account_policy_round_trips_and_casts_bools(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false, 'email' => true, 'phone' => 0, 'address' => 1]);

        $this->assertSame(
            ['avatar' => false, 'email' => true, 'phone' => false, 'address' => true],
            SystemSetting::accountPolicy()
        );
    }

    public function test_set_account_policy_fills_missing_keys_with_true(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false]);

        $this->assertSame(
            ['avatar' => false, 'email' => true, 'phone' => true, 'address' => true],
            SystemSetting::accountPolicy()
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=AccountPolicyTest`
Expected: FAIL — `Call to undefined method ...accountPolicy()`.

- [ ] **Step 3: Add the accessors**

In `back-end/app/Models/SystemSetting.php`, add a key constant alongside the others (after `KEY_ARCHIVE_LAST_RUN` on line 20):

```php
    public const KEY_ACCOUNT_POLICY          = 'account_self_service_policy';
```

Then add these two methods inside the class (e.g. just before the final closing `}`):

```php
    /**
     * Which self-service fields non-admin users may edit, merged over an
     * all-true default so missing keys never lock a field unexpectedly.
     */
    public static function accountPolicy(): array
    {
        $defaults = ['avatar' => true, 'email' => true, 'phone' => true, 'address' => true];
        $stored   = static::getValue(self::KEY_ACCOUNT_POLICY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $out = $defaults;
        foreach ($defaults as $key => $_default) {
            if (array_key_exists($key, $stored)) {
                $out[$key] = (bool) $stored[$key];
            }
        }
        return $out;
    }

    public static function setAccountPolicy(array $policy): void
    {
        $clean = [];
        foreach (['avatar', 'email', 'phone', 'address'] as $key) {
            $clean[$key] = array_key_exists($key, $policy) ? (bool) $policy[$key] : true;
        }
        static::setValue(self::KEY_ACCOUNT_POLICY, $clean);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=AccountPolicyTest`
Expected: PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Models/SystemSetting.php back-end/tests/Feature/Account/AccountPolicyTest.php
git commit -m "feat(account-policy): SystemSetting accountPolicy accessors"
```

---

## Task 2: Admin policy endpoint

**Files:**
- Create: `back-end/app/Http/Controllers/Api/AccountPolicyController.php`
- Modify: `back-end/routes/api.php`
- Test: `back-end/tests/Feature/Account/AccountPolicyTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append these methods to the class in `back-end/tests/Feature/Account/AccountPolicyTest.php`:

```php
    private function makeUser(int $roleId): \App\Models\User
    {
        $uid = uniqid();
        return \App\Models\User::create([
            'first_name'    => 'Pol',
            'last_name'     => 'Icy',
            'username'      => 'pol_' . $uid,
            'email'         => 'pol_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => $roleId,
            'status_id'     => 1,
        ]);
    }

    public function test_admin_can_read_policy(): void
    {
        $admin = $this->makeUser(1);

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/system/account-policy');

        $resp->assertStatus(200);
        $resp->assertJson(['avatar' => true, 'email' => true, 'phone' => true, 'address' => true]);
    }

    public function test_admin_can_update_policy(): void
    {
        $admin = $this->makeUser(1);

        $resp = $this->actingAs($admin, 'sanctum')->putJson('/api/system/account-policy', [
            'avatar' => false, 'email' => false, 'phone' => true, 'address' => true,
        ]);

        $resp->assertStatus(200);
        $resp->assertJson(['avatar' => false, 'email' => false, 'phone' => true, 'address' => true]);
        $this->assertFalse(SystemSetting::accountPolicy()['avatar']);
    }

    public function test_non_admin_cannot_read_or_update_policy(): void
    {
        $user = $this->makeUser(2); // inventory_analyst

        $this->actingAs($user, 'sanctum')->getJson('/api/system/account-policy')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->putJson('/api/system/account-policy', [
            'avatar' => false, 'email' => false, 'phone' => false, 'address' => false,
        ])->assertStatus(403);
    }

    public function test_update_requires_all_four_booleans(): void
    {
        $admin = $this->makeUser(1);

        $this->actingAs($admin, 'sanctum')->putJson('/api/system/account-policy', [
            'avatar' => true,
        ])->assertStatus(422);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=AccountPolicyTest`
Expected: the four new tests FAIL (route 404 / 405).

- [ ] **Step 3: Create the controller**

Create `back-end/app/Http/Controllers/Api/AccountPolicyController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class AccountPolicyController extends Controller
{
    public function show()
    {
        return response()->json(SystemSetting::accountPolicy());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'avatar'  => 'required|boolean',
            'email'   => 'required|boolean',
            'phone'   => 'required|boolean',
            'address' => 'required|boolean',
        ]);

        SystemSetting::setAccountPolicy($validated);

        return response()->json(SystemSetting::accountPolicy());
    }
}
```

- [ ] **Step 4: Register the routes**

In `back-end/routes/api.php`, add the import near the other `use App\Http\Controllers\Api\...;` lines at the top:

```php
use App\Http\Controllers\Api\AccountPolicyController;
```

Then add these two lines immediately after the existing company-settings routes (after line 119, `Route::middleware('role:admin')->put('/system/company-settings', ...)`):

```php
    Route::middleware('role:admin')->get('/system/account-policy', [AccountPolicyController::class, 'show']);
    Route::middleware('role:admin')->put('/system/account-policy', [AccountPolicyController::class, 'update']);
```

- [ ] **Step 5: Run to verify they pass**

Run: `php artisan test --filter=AccountPolicyTest`
Expected: PASS (7 passed).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AccountPolicyController.php back-end/routes/api.php back-end/tests/Feature/Account/AccountPolicyTest.php
git commit -m "feat(account-policy): admin GET/PUT /system/account-policy"
```

---

## Task 3: Enforce policy in AccountController

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AccountController.php`
- Test: `back-end/tests/Feature/Account/AccountPolicyEnforcementTest.php`

- [ ] **Step 1: Write the failing tests**

Create `back-end/tests/Feature/Account/AccountPolicyEnforcementTest.php`:

```php
<?php

namespace Tests\Feature\Account;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountPolicyEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId = 4): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Enf',
            'last_name'     => 'Orce',
            'username'      => 'enf_' . $uid,
            'email'         => 'enf_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => $roleId,
            'status_id'     => 1,
            'phone'         => '0917 000 0000',
            'address'       => 'Old Address',
        ]);
    }

    private function lockAll(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false, 'email' => false, 'phone' => false, 'address' => false]);
    }

    public function test_profile_reports_effective_policy_for_non_admin(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false, 'email' => true, 'phone' => false, 'address' => true]);
        $user = $this->makeUser(4);

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/me/profile');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.policy.avatar', false);
        $resp->assertJsonPath('data.policy.email', true);
        $resp->assertJsonPath('data.policy.phone', false);
        $resp->assertJsonPath('data.policy.address', true);
    }

    public function test_profile_reports_all_true_for_admin_regardless_of_policy(): void
    {
        $this->lockAll();
        $admin = $this->makeUser(1);

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/me/profile');

        $resp->assertJsonPath('data.policy.avatar', true);
        $resp->assertJsonPath('data.policy.email', true);
        $resp->assertJsonPath('data.policy.phone', true);
        $resp->assertJsonPath('data.policy.address', true);
    }

    public function test_locked_avatar_blocks_upload_for_non_admin(): void
    {
        Storage::fake('public');
        $this->lockAll();
        $user = $this->makeUser(4);

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ]);

        $resp->assertStatus(403);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_locked_email_blocks_change_for_non_admin(): void
    {
        $this->lockAll();
        $user = $this->makeUser(4);
        $original = $user->email;

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local' => 'newhandle', 'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(403);
        $this->assertSame($original, $user->fresh()->email);
    }

    public function test_locked_phone_and_address_not_applied_but_name_saves(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => true, 'email' => true, 'phone' => false, 'address' => false]);
        $user = $this->makeUser(4);

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'Renamed',
            'last_name'  => 'User',
            'phone'      => '0999 999 9999',
            'address'    => 'New Address',
        ]);

        $resp->assertStatus(200);
        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->first_name);   // name always editable
        $this->assertSame('0917 000 0000', $fresh->phone);  // locked → unchanged
        $this->assertSame('Old Address', $fresh->address);  // locked → unchanged
    }

    public function test_admin_bypasses_all_locks(): void
    {
        Storage::fake('public');
        $this->lockAll();
        $admin = $this->makeUser(1);

        // avatar
        $this->actingAs($admin, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ])->assertStatus(200);
        // email
        $this->actingAs($admin, 'sanctum')->putJson('/api/me/email', [
            'email_local' => 'adminnew', 'current_password' => 'Secret123!',
        ])->assertStatus(200);
        // phone/address
        $this->actingAs($admin, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'Admin', 'last_name' => 'Boss',
            'phone' => '0918 111 1111', 'address' => 'HQ',
        ])->assertStatus(200);

        $fresh = $admin->fresh();
        $this->assertNotNull($fresh->avatar_path);
        $this->assertSame('adminnew@airking.com', $fresh->email);
        $this->assertSame('0918 111 1111', $fresh->phone);
        $this->assertSame('HQ', $fresh->address);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=AccountPolicyEnforcementTest`
Expected: FAIL — `data.policy` missing and locks not enforced.

- [ ] **Step 3: Add the effective-policy helper + SystemSetting import**

In `back-end/app/Http/Controllers/Api/AccountController.php`, add the import after the existing `use App\Models\User;` (line 6):

```php
use App\Models\SystemSetting;
```

Add this private helper inside the class, right after the `EMAIL_DOMAIN` constant (after line 19):

```php
    /**
     * Which fields this user may self-edit. Admins (role_id 1) always manage
     * their own account, so their effective policy is fully permissive
     * regardless of the global setting.
     */
    private function effectivePolicy(User $user): array
    {
        if ((int) $user->role_id === 1) {
            return ['avatar' => true, 'email' => true, 'phone' => true, 'address' => true];
        }
        return SystemSetting::accountPolicy();
    }
```

- [ ] **Step 4: Report the policy in presentProfile**

In `presentProfile()`, add the effective policy to the returned array. Change the `return array_merge(...)` (lines 35-38) to:

```php
        return array_merge($user->loadMissing(['role', 'status', 'location'])->toArray(), [
            'email_local'  => $local,
            'email_domain' => self::EMAIL_DOMAIN,
            'policy'       => $this->effectivePolicy($user),
        ]);
```

- [ ] **Step 5: Enforce in update() (phone/address gating)**

Replace the body of `update()` between `$validated = $request->validate([...]);` and the `return` so locked fields are dropped. The new `update()` method body:

```php
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:50',
                'last_name'  => 'required|string|max:50',
                'phone'      => 'nullable|string|max:20',
                'address'    => 'nullable|string|max:255',
            ]);

            $user   = $request->user();
            $policy = $this->effectivePolicy($user);

            // Name is always editable; phone/address only when policy allows.
            $fields = [
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
            ];
            if ($policy['phone'] && array_key_exists('phone', $validated)) {
                $fields['phone'] = $validated['phone'];
            }
            if ($policy['address'] && array_key_exists('address', $validated)) {
                $fields['address'] = $validated['address'];
            }

            $user->update($fields);

            return $this->success($this->presentProfile($user->fresh()), 'Profile updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update profile: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 6: Enforce in uploadAvatar() and deleteAvatar()**

In `uploadAvatar()`, add the guard as the first statement inside `try` (before `$request->validate`):

```php
            if (! $this->effectivePolicy($request->user())['avatar']) {
                return $this->forbidden('Profile photo changes are turned off by your administrator.');
            }
```

In `deleteAvatar()`, add the same guard as the first statement inside `try` (before `$user = $request->user();`):

```php
            if (! $this->effectivePolicy($request->user())['avatar']) {
                return $this->forbidden('Profile photo changes are turned off by your administrator.');
            }
```

- [ ] **Step 7: Enforce in updateEmail()**

In `updateEmail()`, add the guard immediately after `$user = $request->user();` (after line 141):

```php
            if (! $this->effectivePolicy($user)['email']) {
                return $this->forbidden('Email changes are turned off by your administrator.');
            }
```

- [ ] **Step 8: Run the enforcement tests + the full Account suite**

Run: `php artisan test --filter=AccountPolicyEnforcementTest`
Expected: PASS (6 passed).

Then: `php artisan test tests/Feature/Account`
Expected: all green (existing 22 + AccountPolicyTest 7 + AccountPolicyEnforcementTest 6 = 35).

- [ ] **Step 9: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AccountController.php back-end/tests/Feature/Account/AccountPolicyEnforcementTest.php
git commit -m "feat(account-policy): enforce effective policy on /me/* writes + report in profile"
```

---

## Task 4: Frontend accountPolicyAPI

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the API object**

In `frontend/src/admin/services/api.js`, find the `systemSettingsAPI` export (begins `export const systemSettingsAPI = {` around line 391) and add this new export immediately after that object's closing `};`:

```js
/** Account self-service policy (admin only): which fields users may edit. */
export const accountPolicyAPI = {
  get: async () => apiRequest('/system/account-policy'),
  update: async (policy) =>
    apiRequest('/system/account-policy', { method: 'PUT', body: JSON.stringify(policy) }),
};
```

- [ ] **Step 2: Verify the build**

Run (from `frontend/`): `CI=false npm run build`
Expected: compiles; no new errors referencing `api.js`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "feat(account-policy): accountPolicyAPI client"
```

---

## Task 5: System Config "User Accounts" policy tab

**Files:**
- Modify: `frontend/src/admin/pages/SystemConfiguration.jsx`

This repurposes the dead "Profile Settings" tab. Work in `frontend/src/admin/pages/SystemConfiguration.jsx`.

- [ ] **Step 1: Add the API import**

Change the services/api import line (line 5) to add `accountPolicyAPI`:

```jsx
import { getApiBaseUrl, getToken, systemSettingsAPI, companySettingsAPI, accountPolicyAPI, setSessionTimeoutMinutes, passwordResetAPI } from '../services/api';
```

- [ ] **Step 2: Add a reusable toggle component**

Add this module-level component just above `const TABS = [` (line 134):

```jsx
const PolicyToggle = ({ id, label, desc, checked, onChange }) => (
  <div className="sc-checkbox-row sc-checkbox-row--accent">
    <span className="sc-checkbox-icon sc-checkbox-icon--indigo">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
    </span>
    <input type="checkbox" id={id} checked={checked} onChange={onChange} />
    <label htmlFor={id}>
      <span className="sc-checkbox-label">{label}</span>
      <span className="sc-checkbox-desc">{desc}</span>
    </label>
  </div>
);
```

- [ ] **Step 3: Rename the tab**

In the `TABS` array (lines 134-139), change the `profile` entry:

```jsx
  { id: 'accounts', label: 'User Accounts', icon: <PersonIcon /> },
```

- [ ] **Step 4: Remove the admin-profile stub state + handlers**

Delete `DEFAULT_PROFILE` (lines 151-158).

Delete the `profile` state and `fileInputRef` (lines 172-173):

```jsx
  const [profile, setProfile] = useState(DEFAULT_PROFILE);
  const fileInputRef = useRef(null);
```

Add the policy state where those were:

```jsx
  const [policy, setPolicy] = useState({ avatar: true, email: true, phone: true, address: true });
  const [policyLoading, setPolicyLoading] = useState(false);
  const [policySaving, setPolicySaving] = useState(false);
```

In the mount `useEffect` (starts line 258), delete the "Load user profile from localStorage" block (lines 259-273, the `const stored = localStorage.getItem('user'); ... } catch {}` block) and replace it with a policy load:

```jsx
    const loadPolicy = async () => {
      setPolicyLoading(true);
      try {
        const p = await accountPolicyAPI.get();
        setPolicy({ avatar: !!p.avatar, email: !!p.email, phone: !!p.phone, address: !!p.address });
      } catch {
        // keep permissive defaults if unavailable
      } finally {
        setPolicyLoading(false);
      }
    };
    loadPolicy();
```

Delete `handleProfileChange` (lines 363-365) and `handleProfileSave` (lines 367-369), and add the policy handlers in their place:

```jsx
  const togglePolicy = (field) =>
    setPolicy((prev) => ({ ...prev, [field]: !prev[field] }));

  const handlePolicySave = async () => {
    setPolicySaving(true);
    try {
      const saved = await accountPolicyAPI.update(policy);
      setPolicy({ avatar: !!saved.avatar, email: !!saved.email, phone: !!saved.phone, address: !!saved.address });
      toast.success('Account policy saved');
    } catch (err) {
      toast.error("Couldn't save account policy", err.message);
    } finally {
      setPolicySaving(false);
    }
  };
```

- [ ] **Step 5: Replace the profile tab body with the policy editor**

Replace the entire `{activeTab === 'profile' && ( ... )}` block (lines 627-742) with:

```jsx
        {activeTab === 'accounts' && (
          <div className="sc-tab-content">
            <div className="sc-card">
              <div className="sc-card-header">
                <h3>User Account Editing</h3>
                <p>Choose what non-admin users can change on their own Account Settings page. Admins always manage their own account.</p>
              </div>
              <div className="sc-card-body">
                {policyLoading ? (
                  <Loader label="Loading account policy" />
                ) : (
                  <>
                    <PolicyToggle id="policy-avatar" label="Profile picture"
                      desc="Upload or remove their own profile photo."
                      checked={policy.avatar} onChange={() => togglePolicy('avatar')} />
                    <PolicyToggle id="policy-email" label="Email address"
                      desc="Change the part of their sign-in email before @airking.com."
                      checked={policy.email} onChange={() => togglePolicy('email')} />
                    <PolicyToggle id="policy-phone" label="Phone number"
                      desc="Edit their contact number."
                      checked={policy.phone} onChange={() => togglePolicy('phone')} />
                    <PolicyToggle id="policy-address" label="Address"
                      desc="Edit their address."
                      checked={policy.address} onChange={() => togglePolicy('address')} />
                  </>
                )}
              </div>
            </div>
          </div>
        )}
```

- [ ] **Step 6: Wire the footer Save for the accounts tab**

Replace the footer block (lines 1015-1033, `{(activeTab === 'security' || activeTab === 'users' || activeTab === 'company') && ( ... )}`) with:

```jsx
        {(activeTab === 'security' || activeTab === 'accounts' || activeTab === 'users' || activeTab === 'company') && (
          <div className="sc-footer">
            <button className="sc-btn-reset" onClick={handleResetDefaults} style={{ visibility: (activeTab === 'company' || activeTab === 'accounts') ? 'hidden' : 'visible' }}>Reset to Defaults</button>
            <button
              className="sc-btn-save"
              disabled={(activeTab === 'company' && companySaving) || (activeTab === 'accounts' && policySaving)}
              onClick={
                activeTab === 'security' ? handleSave :
                activeTab === 'company' ? handleCompanySave :
                activeTab === 'accounts' ? handlePolicySave :
                () => toast.success('User settings saved!')
              }
            >
              <SaveIcon />
              {activeTab === 'security' ? 'Save Security Settings' :
               activeTab === 'company' ? (companySaving ? 'Saving…' : 'Save Company Settings') :
               activeTab === 'accounts' ? (policySaving ? 'Saving…' : 'Save Account Policy') :
               'Save User Settings'}
            </button>
          </div>
        )}
```

- [ ] **Step 7: Remove now-unused icon components**

`CameraIcon` (lines 56-60), `CalendarIcon` (lines 86-90), and `BriefcaseIcon` (lines 92-96) were only used by the deleted profile tab. Delete those three component definitions to keep the build warning-free. (Leave `PersonIcon`, `AvatarPersonIcon`, `MailIcon`, `PhoneIcon`, `MapPinIcon`, `SaveIcon` — still used elsewhere.)

- [ ] **Step 8: Verify the build**

Run (from `frontend/`): `CI=false npm run build`
Expected: compiles with no new errors. (If the build flags an unused var, remove that leftover reference.)

- [ ] **Step 9: Commit**

```bash
git add frontend/src/admin/pages/SystemConfiguration.jsx
git commit -m "feat(account-policy): repurpose Profile Settings tab into User Accounts policy editor"
```

---

## Task 6: Disable locked fields on the Account Settings page

**Files:**
- Modify: `frontend/src/admin/pages/AccountSettingsPage.jsx`
- Modify: `frontend/src/admin/styles/account_settings.css`

- [ ] **Step 1: Add the managed-note + disabled-input styles**

Append to `frontend/src/admin/styles/account_settings.css`:

```css
/* ----- admin-locked fields ---------------------------------------------- */
.ak-page .as-managed {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.15rem;
  font-family: var(--ak-mono);
  font-size: 0.68rem;
  letter-spacing: 0.04em;
  color: var(--ak-ink-faint);
}
.ak-page .as-managed svg { width: 12px; height: 12px; }
.ak-page .ak-input:disabled {
  background: var(--ak-band);
  color: var(--ak-ink-faint);
  cursor: not-allowed;
}
```

- [ ] **Step 2: Add a ManagedNote component + read the policy**

In `frontend/src/admin/pages/AccountSettingsPage.jsx`, add a small component just above `const AccountSettingsPage = () => {` (after the `LockIcon` definition):

```jsx
const ManagedNote = () => (
  <span className="as-managed"><LockIcon /> Managed by your administrator</span>
);
```

Inside the component, just after the `roleLabel` line (right before the `return (`), derive the policy with a permissive fallback:

```jsx
  const policy = profile?.policy || { avatar: true, email: true, phone: true, address: true };
```

- [ ] **Step 3: Gate the profile-photo actions**

In the identity card's `as-identity-actions` div, replace the Upload/Remove buttons block so it shows the note when avatar editing is locked. Replace:

```jsx
            <div className="as-identity-actions">
              <input ref={fileRef} type="file" accept="image/*" onChange={onPickFile} style={{ display: 'none' }} />
              <button type="button" className="ak-btn ak-btn--primary" disabled={busy.avatar} onClick={() => fileRef.current?.click()}>
                {busy.avatar ? <span className="ak-spin" /> : <CameraIcon />}
                {profile?.avatar_url ? 'Change photo' : 'Upload photo'}
              </button>
              {profile?.avatar_url && (
                <button type="button" className="ak-btn ak-btn--danger" disabled={busy.avatar} onClick={removeAvatar}>
                  <TrashIcon /> Remove
                </button>
              )}
            </div>
```

with:

```jsx
            <div className="as-identity-actions">
              {policy.avatar ? (
                <>
                  <input ref={fileRef} type="file" accept="image/*" onChange={onPickFile} style={{ display: 'none' }} />
                  <button type="button" className="ak-btn ak-btn--primary" disabled={busy.avatar} onClick={() => fileRef.current?.click()}>
                    {busy.avatar ? <span className="ak-spin" /> : <CameraIcon />}
                    {profile?.avatar_url ? 'Change photo' : 'Upload photo'}
                  </button>
                  {profile?.avatar_url && (
                    <button type="button" className="ak-btn ak-btn--danger" disabled={busy.avatar} onClick={removeAvatar}>
                      <TrashIcon /> Remove
                    </button>
                  )}
                </>
              ) : (
                <ManagedNote />
              )}
            </div>
```

- [ ] **Step 4: Gate the phone + address inputs**

In the Personal information form, add `disabled` + a note to the phone field. Replace the phone `ak-field` block:

```jsx
              <div className="ak-field">
                <label className="ak-label" htmlFor="phone">Phone</label>
                <input id="phone" className="ak-input" value={info.phone}
                  onChange={(e) => setInfo({ ...info, phone: e.target.value })} placeholder="e.g. 0917 123 4567" />
              </div>
```

with:

```jsx
              <div className="ak-field">
                <label className="ak-label" htmlFor="phone">Phone</label>
                <input id="phone" className="ak-input" value={info.phone} disabled={!policy.phone}
                  onChange={(e) => setInfo({ ...info, phone: e.target.value })} placeholder="e.g. 0917 123 4567" />
                {!policy.phone && <ManagedNote />}
              </div>
```

Replace the address `ak-field` block:

```jsx
              <div className="ak-field as-col-full">
                <label className="ak-label" htmlFor="address">Address</label>
                <input id="address" className="ak-input" value={info.address}
                  onChange={(e) => setInfo({ ...info, address: e.target.value })} placeholder="Street, city" />
              </div>
```

with:

```jsx
              <div className="ak-field as-col-full">
                <label className="ak-label" htmlFor="address">Address</label>
                <input id="address" className="ak-input" value={info.address} disabled={!policy.address}
                  onChange={(e) => setInfo({ ...info, address: e.target.value })} placeholder="Street, city" />
                {!policy.address && <ManagedNote />}
              </div>
```

- [ ] **Step 5: Gate the email section**

In the Sign-in email form, disable both inputs and the submit when email editing is locked, and show the note. Replace the email-local `ak-field` block:

```jsx
              <div className="ak-field">
                <label className="ak-label" htmlFor="email_local">Email</label>
                <div className="as-emailgroup">
                  <input id="email_local" className="ak-input" value={emailLocal}
                    onChange={(e) => setEmailLocal(e.target.value)} autoComplete="username" required />
                  <span className="as-emaildomain"><LockIcon />{EMAIL_DOMAIN}</span>
                </div>
                {emailErr.local && <span className="as-error">{emailErr.local}</span>}
              </div>
```

with:

```jsx
              <div className="ak-field">
                <label className="ak-label" htmlFor="email_local">Email</label>
                <div className="as-emailgroup">
                  <input id="email_local" className="ak-input" value={emailLocal} disabled={!policy.email}
                    onChange={(e) => setEmailLocal(e.target.value)} autoComplete="username" required />
                  <span className="as-emaildomain"><LockIcon />{EMAIL_DOMAIN}</span>
                </div>
                {!policy.email && <ManagedNote />}
                {emailErr.local && <span className="as-error">{emailErr.local}</span>}
              </div>
```

Replace the email current-password `ak-field` block:

```jsx
              <div className="ak-field">
                <label className="ak-label" htmlFor="email_current_password">Current password</label>
                <input id="email_current_password" className="ak-input" type="password" value={emailPw}
                  onChange={(e) => setEmailPw(e.target.value)} autoComplete="current-password" required />
                {emailErr.pw && <span className="as-error">{emailErr.pw}</span>}
              </div>
```

with:

```jsx
              <div className="ak-field">
                <label className="ak-label" htmlFor="email_current_password">Current password</label>
                <input id="email_current_password" className="ak-input" type="password" value={emailPw} disabled={!policy.email}
                  onChange={(e) => setEmailPw(e.target.value)} autoComplete="current-password" required={policy.email} />
                {emailErr.pw && <span className="as-error">{emailErr.pw}</span>}
              </div>
```

Replace the email submit button:

```jsx
              <button type="submit" className="ak-btn ak-btn--primary" disabled={busy.email}>
                {busy.email && <span className="ak-spin" />}{busy.email ? 'Saving' : 'Update email'}
              </button>
```

with:

```jsx
              <button type="submit" className="ak-btn ak-btn--primary" disabled={busy.email || !policy.email}>
                {busy.email && <span className="ak-spin" />}{busy.email ? 'Saving' : 'Update email'}
              </button>
```

- [ ] **Step 6: Verify the build**

Run (from `frontend/`): `CI=false npm run build`
Expected: compiles with no new errors.

- [ ] **Step 7: Manual smoke test**

Start back-end (`php artisan serve --host=0.0.0.0` from `back-end/`) and frontend (`npm start` from `frontend/`). As an admin: System Configuration → **User Accounts** tab → turn off **Profile picture** and **Phone**, Save. Log in as a non-admin (or open Account Settings) → confirm the photo actions are replaced by the managed note and the Phone field is disabled with the note, while Name and Password remain editable. Turn them back on → controls return.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/admin/pages/AccountSettingsPage.jsx frontend/src/admin/styles/account_settings.css
git commit -m "feat(account-policy): disable admin-locked fields on Account Settings with a note"
```

---

## Self-Review Notes

- **Spec coverage:** JSON policy blob + accessors (Task 1) ✓; admin GET/PUT endpoint, admin-only (Task 2) ✓; effective policy + admin bypass, enforce avatar/email 403 + phone/address gating, report policy in /me/profile (Task 3) ✓; accountPolicyAPI (Task 4) ✓; repurposed System Config tab + remove stub (Task 5) ✓; disabled locked fields + managed note on Account Settings (Task 6) ✓.
- **Naming consistency:** policy keys `avatar/email/phone/address` are identical across `SystemSetting`, controller, API, and both pages. `effectivePolicy()`, `accountPolicy()`, `setAccountPolicy()`, `accountPolicyAPI` used consistently.
- **Field set:** name + password are never gated — `update()` always writes name; `updatePassword()` is untouched.
- **Admin bypass** is centralized in `effectivePolicy()` so enforcement and `/me/profile` reporting can't diverge.
- **No placeholders:** every step has concrete code/commands.
