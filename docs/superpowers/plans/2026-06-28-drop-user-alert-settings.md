# Drop `user_alert_settings` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the `user_alert_settings` table and relocate its single piece of state (`pending_approvals_last_seen_at`, one timestamp per user) to a new `users.alerts_last_seen_at` column, with no change to the alerts badge / "mark all read" behavior or API response shape.

**Architecture:** A single migration adds `users.alerts_last_seen_at`, backfills it from `user_alert_settings`, then drops the table. `AlertSettingsController` reads/writes the column directly off the already-resolved `$user` model instead of the `UserAlertSettings` model, which is deleted. The `/alerts/settings` and `/alerts/mark-all-read` JSON responses keep their existing keys, so the frontend and `DashboardController::layoutSummary()` need no changes.

**Tech Stack:** Laravel 11 (PHP), MariaDB (XAMPP), PHPUnit feature tests with `DatabaseTransactions` against `airkingV5_test`. This repo is SQL-dump based (`airking_cleans.sql` is the canonical schema), with targeted migrations applied via `--path`.

**Reference spec:** `docs/superpowers/specs/2026-06-28-drop-user-alert-settings-design.md`

---

## File Structure

- **Create:** `back-end/database/migrations/2026_06_28_000000_drop_user_alert_settings_table.php` — adds the column, backfills, drops the table (reversible).
- **Create:** `back-end/tests/Feature/Alerts/MarkAllReadTest.php` — proves read-state persists on the new column and the response shape is preserved.
- **Modify:** `back-end/app/Models/User.php` — add `alerts_last_seen_at` to `$fillable` and `$casts`.
- **Modify:** `back-end/app/Http/Controllers/Api/AlertSettingsController.php` — read/write the column; drop the `UserAlertSettings` import.
- **Delete:** `back-end/app/Models/UserAlertSettings.php`.
- **Modify:** `airking_cleans.sql` — add the column to the `users` definition; remove the `user_alert_settings` table structure, data, indexes, and AUTO_INCREMENT.

All `php artisan` and `php artisan test` commands run from the `back-end/` directory. The `mysql` commands run from anywhere (git-bash).

---

## Task 1: Add `users.alerts_last_seen_at`, switch code to it, drop the table

**Files:**
- Create: `back-end/database/migrations/2026_06_28_000000_drop_user_alert_settings_table.php`
- Create: `back-end/tests/Feature/Alerts/MarkAllReadTest.php`
- Modify: `back-end/app/Models/User.php:32-53`
- Modify: `back-end/app/Http/Controllers/Api/AlertSettingsController.php`
- Delete: `back-end/app/Models/UserAlertSettings.php`

- [ ] **Step 1: Add the column to the `User` model**

In `back-end/app/Models/User.php`, add `'alerts_last_seen_at'` to `$fillable` (after `'otp_expires_at'`):

```php
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'username',
        'password_hash',
        'phone',
        'role_id',
        'location_id',
        'status_id',
        'otp_code',
        'otp_expires_at',
        'alerts_last_seen_at',
    ];
```

And add the cast:

```php
    protected $casts = [
        'email_verified_at'   => 'datetime',
        'alerts_last_seen_at' => 'datetime',
    ];
```

- [ ] **Step 2: Write the migration**

Create `back-end/database/migrations/2026_06_28_000000_drop_user_alert_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the standalone `user_alert_settings` table with a single
 * `users.alerts_last_seen_at` column. The table only ever held one timestamp
 * per user (the last time they clicked "mark all read" on the alerts bell),
 * which is user-scoped state that belongs on the user record. Existing
 * non-null read-state is backfilled before the table is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'alerts_last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('alerts_last_seen_at')->nullable()->after('otp_expires_at');
            });
        }

        if (Schema::hasTable('user_alert_settings')) {
            DB::statement('
                UPDATE users u
                JOIN user_alert_settings s ON s.user_id = u.user_id
                SET u.alerts_last_seen_at = s.pending_approvals_last_seen_at
                WHERE s.pending_approvals_last_seen_at IS NOT NULL
            ');

            Schema::dropIfExists('user_alert_settings');
        }
    }

    public function down(): void
    {
        Schema::create('user_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->timestamp('pending_approvals_last_seen_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::statement('
            INSERT INTO user_alert_settings (user_id, pending_approvals_last_seen_at, created_at, updated_at)
            SELECT user_id, alerts_last_seen_at, NOW(), NOW()
            FROM users
            WHERE alerts_last_seen_at IS NOT NULL
        ');

        if (Schema::hasColumn('users', 'alerts_last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('alerts_last_seen_at');
            });
        }
    }
};
```

- [ ] **Step 3: Apply the migration to the dev DB**

Run (from `back-end/`):

```bash
php artisan migrate --path=database/migrations/2026_06_28_000000_drop_user_alert_settings_table.php
```

Expected: `Migrating: 2026_06_28_000000_drop_user_alert_settings_table` then `Migrated: ... DONE`.

Verify the column exists and the table is gone:

```bash
/c/XAMPP1/mysql/bin/mysql.exe -u root -e "SHOW COLUMNS FROM users LIKE 'alerts_last_seen_at'; SHOW TABLES LIKE 'user_alert_settings'; SELECT user_id, alerts_last_seen_at FROM users WHERE alerts_last_seen_at IS NOT NULL;" airkingV5
```

Expected: one column row for `alerts_last_seen_at`; **no** rows for the `SHOW TABLES` (table dropped); the backfilled user (`user_id = 2`) shows a non-null timestamp.

- [ ] **Step 4: Apply the same change to the test DB (`airkingV5_test`)**

The PHPUnit suite runs against `airkingV5_test` with `DatabaseTransactions` (it does not re-migrate). Apply the equivalent DDL directly so the test DB matches:

```bash
/c/XAMPP1/mysql/bin/mysql.exe -u root airkingV5_test -e "
ALTER TABLE users ADD COLUMN IF NOT EXISTS alerts_last_seen_at TIMESTAMP NULL DEFAULT NULL AFTER otp_expires_at;
UPDATE users u JOIN user_alert_settings s ON s.user_id = u.user_id SET u.alerts_last_seen_at = s.pending_approvals_last_seen_at WHERE s.pending_approvals_last_seen_at IS NOT NULL;
DROP TABLE IF EXISTS user_alert_settings;
"
```

Expected: no error. (If `airkingV5_test` never had `user_alert_settings`, the `UPDATE`/`DROP` are harmless no-ops thanks to the join / `IF EXISTS`.)

Verify:

```bash
/c/XAMPP1/mysql/bin/mysql.exe -u root airkingV5_test -e "SHOW COLUMNS FROM users LIKE 'alerts_last_seen_at'; SHOW TABLES LIKE 'user_alert_settings';"
```

Expected: column present; no `user_alert_settings` table.

- [ ] **Step 5: Write the failing feature test**

Create `back-end/tests/Feature/Alerts/MarkAllReadTest.php`:

```php
<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MarkAllReadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mark_all_read_persists_timestamp_on_users_column(): void
    {
        $uid  = uniqid();
        $user = User::create([
            'first_name'    => 'Alert',
            'last_name'     => 'Reader',
            'username'      => 'alert_reader_' . $uid,
            'email'         => 'alert_reader_' . $uid . '@alerts.test',
            'password_hash' => bcrypt('x'),
            'role_id'       => 1,
        ]);

        $this->assertNull($user->alerts_last_seen_at, 'New user starts with null read-state.');

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/alerts/mark-all-read');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull(
            $resp->json('data.pending_approvals_last_seen_at'),
            'Response keeps the pending_approvals_last_seen_at key for the frontend contract.'
        );

        $this->assertNotNull(
            $user->fresh()->alerts_last_seen_at,
            'mark-all-read must persist the timestamp on users.alerts_last_seen_at.'
        );
    }

    public function test_settings_returns_last_seen_key_from_users_column(): void
    {
        $uid  = uniqid();
        $user = User::create([
            'first_name'    => 'Alert',
            'last_name'     => 'Settings',
            'username'      => 'alert_settings_' . $uid,
            'email'         => 'alert_settings_' . $uid . '@alerts.test',
            'password_hash' => bcrypt('x'),
            'role_id'       => 1,
        ]);

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/alerts/settings');

        $resp->assertStatus(200);
        $resp->assertJsonStructure([
            'data' => ['badge_count', 'unread_count', 'pending_approvals_last_seen_at'],
        ]);
    }
}
```

- [ ] **Step 6: Run the test to verify it fails**

Run (from `back-end/`):

```bash
php artisan test --filter=MarkAllReadTest
```

Expected: FAIL. The current `AlertSettingsController` still calls `UserAlertSettings::getOrCreateForUser()`, which queries the now-dropped `user_alert_settings` table → a 500 / `QueryException` ("Base table or view not found"), so both tests fail.

- [ ] **Step 7: Update `AlertSettingsController::index()`**

In `back-end/app/Http/Controllers/Api/AlertSettingsController.php`, replace these two lines:

```php
        $settings = UserAlertSettings::getOrCreateForUser($userId);
        $lastSeen = $settings?->pending_approvals_last_seen_at;
```

with:

```php
        $lastSeen = $user->alerts_last_seen_at;
```

Then, in the JSON response at the bottom of `index()`, replace:

```php
                'pending_approvals_last_seen_at' => $settings?->pending_approvals_last_seen_at?->toIso8601String(),
```

with:

```php
                'pending_approvals_last_seen_at' => $user->alerts_last_seen_at?->toIso8601String(),
```

(The `$userId` variable is still used for nothing else after this change — leave the `$userId = $user->getKey();` line as-is; it is harmless. The existing `if ($lastSeen)` branch already handles the null case.)

- [ ] **Step 8: Update `AlertSettingsController::markAllRead()`**

Replace the entire `markAllRead()` method body with:

```php
    public function markAllRead(): JsonResponse
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $user->alerts_last_seen_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'All alerts marked as read',
            'data' => [
                'pending_approvals_last_seen_at' => $user->alerts_last_seen_at?->toIso8601String(),
                'unread_count' => 0,
            ],
        ]);
    }
```

- [ ] **Step 9: Remove the dead import**

In the same file, delete the line:

```php
use App\Models\UserAlertSettings;
```

- [ ] **Step 10: Delete the model**

Delete `back-end/app/Models/UserAlertSettings.php`.

```bash
git rm back-end/app/Models/UserAlertSettings.php
```

- [ ] **Step 11: Run the test to verify it passes**

Run (from `back-end/`):

```bash
php artisan test --filter=MarkAllReadTest
```

Expected: PASS (2 tests, both green).

- [ ] **Step 12: Confirm nothing else references the old model/table**

Run:

```bash
git grep -n "UserAlertSettings\|user_alert_settings" -- back-end/app back-end/routes
```

Expected: **no output** (no remaining references in application code). Matches in `back-end/database/migrations/` and `docs/` are expected and fine.

- [ ] **Step 13: Commit**

```bash
git add back-end/app/Models/User.php \
        back-end/app/Http/Controllers/Api/AlertSettingsController.php \
        back-end/database/migrations/2026_06_28_000000_drop_user_alert_settings_table.php \
        back-end/tests/Feature/Alerts/MarkAllReadTest.php
git rm --cached back-end/app/Models/UserAlertSettings.php 2>/dev/null; true
git commit -m "refactor(alerts): drop user_alert_settings, move read-state to users.alerts_last_seen_at

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

(If `git rm` in Step 10 already staged the deletion, the `git rm --cached` line is a harmless no-op.)

---

## Task 2: Update the canonical SQL dump

**Files:**
- Modify: `airking_cleans.sql`

This repo treats `airking_cleans.sql` as the source-of-truth schema, so it must reflect the dropped table and the new column.

- [ ] **Step 1: Add the column to the `users` table definition**

In `airking_cleans.sql`, find the `CREATE TABLE \`users\`` block. After the `otp_expires_at` column line, add:

```sql
  `alerts_last_seen_at` timestamp NULL DEFAULT NULL,
```

(Place it before `deleted_at` to mirror the live column order.)

- [ ] **Step 2: Remove the `user_alert_settings` table structure**

Delete the block (around lines 1342-1352):

```sql
--
-- Table structure for table `user_alert_settings`
--

CREATE TABLE `user_alert_settings` (
  ...
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 3: Remove the `user_alert_settings` data dump**

Delete the block (around lines 1354-1361):

```sql
--
-- Dumping data for table `user_alert_settings`
--

INSERT INTO `user_alert_settings` (...) VALUES
(1, 2, ...),
(2, 6, ...),
(3, 8, ...);
```

- [ ] **Step 4: Remove the `user_alert_settings` indexes block**

Delete the block (around lines 1791-1795):

```sql
--
-- Indexes for table `user_alert_settings`
--
ALTER TABLE `user_alert_settings`
  ADD PRIMARY KEY (`id`);
```

- [ ] **Step 5: Remove the `user_alert_settings` AUTO_INCREMENT block**

Delete the block (around lines 2059-2063):

```sql
--
-- AUTO_INCREMENT for table `user_alert_settings`
--
ALTER TABLE `user_alert_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
```

- [ ] **Step 6: Verify no `user_alert_settings` references remain in the dump**

```bash
git grep -n "user_alert_settings" -- airking_cleans.sql
```

Expected: **no output**.

- [ ] **Step 7: Commit**

```bash
git add airking_cleans.sql
git commit -m "chore(db): drop user_alert_settings from canonical SQL dump; add users.alerts_last_seen_at

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final Verification

- [ ] **Run the full alerts-related suite** (from `back-end/`):

```bash
php artisan test --filter=Alert
```

Expected: `MarkAllReadTest` (2) and `AlertScopeTest` (1) all pass — confirms the badge/scoping path still works end-to-end against `users.alerts_last_seen_at`.

- [ ] **Spot-check the dev DB state:**

```bash
/c/XAMPP1/mysql/bin/mysql.exe -u root -e "SELECT user_id, alerts_last_seen_at FROM users WHERE alerts_last_seen_at IS NOT NULL; SHOW TABLES LIKE 'user_alert_settings';" airkingV5
```

Expected: backfilled timestamp(s) present; no `user_alert_settings` table.
