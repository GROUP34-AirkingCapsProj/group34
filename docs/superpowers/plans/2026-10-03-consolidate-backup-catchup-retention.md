# Backup Consolidation — Catch-Up Gate and Retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the in-app Backup page the single backup feature for the local install — automatic, admin-configurable, with a catch-up schedule gate and retention pruning — then retire `scripts/backup-db.ps1` and its popup task.

**Architecture:** `BackupService` gains two capabilities the PowerShell script had and it lacks: a schedule gate that fires on the next tick *after* the configured time rather than demanding an exact minute match, and GFS retention pruning that deletes aged snapshot files and marks their history rows. A single hidden Windows task running `php artisan schedule:run` every minute replaces the backup-specific task, which also revives five other dead scheduled jobs. The admin UI gains retention inputs and an explicit timezone label.

**Tech Stack:** Laravel 12 (PHP 8.2), MySQL 8 via XAMPP, React 18 admin SPA, PHPUnit with `DatabaseTransactions`, Windows Task Scheduler.

---

## Context an engineer needs before starting

**Nothing about this feature currently runs automatically.** The in-app backup is complete and working when an admin clicks *Create backup now*, but `backup:run` is registered `->everyMinute()` in `back-end/routes/console.php` and no OS-level task calls `php artisan schedule:run`, so it has never fired. Confirmed by: no `back-end/storage/logs/backup-run.log`, no `back-end/storage/app/backups/` directory, and no such task in `Get-ScheduledTask`.

**The schedule gate as written cannot tolerate a machine that is off.** `BackupService::dueForScheduledRun()` (`back-end/app/Services/BackupService.php:155-172`) requires `now()->format('H:i') === $time` exactly. This install's PC is frequently off at night — the old script's log shows runs landing at 10:19, 05:12, 09:50 rather than its configured 00:30, because `StartWhenAvailable` caught them up. Porting the schedule without a catch-up would skip most days silently. Task 1 fixes this and is the single most important task in the plan.

**There is no retention logic at all.** `grep` for prune/unlink-old across `BackupService` returns only the one `@unlink` that cleans up a failed dump. The service writes an uncompressed `.sql` per run and never deletes one, so the folder grows without bound. Task 3 fixes this.

**The app timezone is UTC.** `back-end/config/app.php:68` is `'timezone' => 'UTC'` and `back-end/.env` sets no `APP_TIMEZONE`. An admin typing `00:30` currently gets a run at 08:30 Philippine time. Task 5 addresses this at the UI layer rather than changing `app.timezone`, because changing the app timezone would reinterpret every existing stored timestamp in the database — out of scope and dangerous.

**Local dev environment quirks that will bite you:**
- `back-end/.env` has `APP_ENV=production`, so `php artisan migrate` requires `--force`.
- Tests run against a separate `airkingV5_test` database which must be migrated separately.
- MySQL is **not** a Windows service on this machine and is not running right now. Start it from the XAMPP Control Panel before running anything that touches the database.
- The live database is `airkingvv6` (`back-end/.env:29`). The old script targets `airkingV5`, which no longer exists.

**Out of scope, state explicitly if asked:** production backup. Render/Aiven runs PostgreSQL (`DB_CONNECTION=pgsql`, `docs/DEPLOY-RENDER.md:163`) and `BackupService` hardcodes `config('database.connections.mysql')` in three places plus emits MySQL-only syntax in `runPhpDump()`. Nothing in this plan makes the feature work against Postgres. Aiven's managed snapshots remain the production answer.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `back-end/app/Services/BackupService.php` | Dump/restore engine, schedule gate, retention | Modify — rewrite `dueForScheduledRun()`, add `pruneOldBackups()` |
| `back-end/app/Console/Commands/RunScheduledBackup.php` | Scheduled entry point | Modify — call prune after a successful backup |
| `back-end/app/Http/Controllers/Api/BackupController.php` | Settings API + stats | Modify — validate and default retention keys, fix `nextRunAt()` |
| `back-end/tests/Feature/Backup/ScheduleGateTest.php` | Catch-up gate behaviour | Create |
| `back-end/tests/Feature/Backup/RetentionTest.php` | Pruning behaviour | Create |
| `back-end/tests/Feature/Backup/SettingsApiTest.php` | Retention settings round-trip | Create |
| `back-end/database/migrations/2026_10_03_000001_add_checksum_to_backup_logs.php` | `checksum_sha256` column | Create |
| `back-end/tests/Feature/Backup/IntegrityTest.php` | Checksum + truncation behaviour | Create |
| `frontend/src/admin/components/BackupPanel.jsx` | Admin schedule UI | Modify — retention fields, timezone label |
| `scripts/install-scheduler-task.ps1` | Registers the hidden `schedule:run` task | Create |
| `scripts/README-backup.md` | Operator documentation | Rewrite |
| `scripts/backup-db.ps1` | Old standalone script | Delete (Task 9, last) |
| `DEPLOYMENT.md` | Scheduler setup docs | Modify — correct the stale "four jobs" line |

Retention lives in `BackupService` rather than a separate class because it operates on the same storage directory the service already resolves via `resolveStorageDir()`, and because `TABLES_EXCLUDED_FROM_DUMP`-style policy constants are already the established pattern in that file. The codebase uses large service classes; splitting would break with convention.

---

## Task 1: Catch-up schedule gate

The gate must fire on the first tick at or after the configured time on a day that has not yet had a successful run, instead of demanding an exact `H:i` match.

**Files:**
- Modify: `back-end/app/Services/BackupService.php:155-172`
- Test: `back-end/tests/Feature/Backup/ScheduleGateTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Backup/ScheduleGateTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The gate used to require an exact H:i match, which silently skipped the whole
 * day whenever the machine was off for that one minute. On this install the PC is
 * frequently off overnight, so "catches up on the next tick" is the behaviour that
 * makes scheduled backups actually happen.
 */
class ScheduleGateTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): BackupService
    {
        return app(BackupService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_disabled_schedule_is_never_due(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => false,
            'time'    => '00:30',
        ]));
    }

    public function test_not_due_before_the_configured_time(): void
    {
        Carbon::setTestNow('2026-10-03 00:29:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => '00:30',
        ]));
    }

    public function test_due_exactly_at_the_configured_time(): void
    {
        Carbon::setTestNow('2026-10-03 00:30:00');

        $this->assertTrue($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => '00:30',
        ]));
    }

    public function test_due_hours_late_when_the_exact_minute_was_missed(): void
    {
        Carbon::setTestNow('2026-10-03 09:14:00');

        $this->assertTrue($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => '00:30',
        ]));
    }

    public function test_not_due_again_once_today_has_succeeded(): void
    {
        Carbon::setTestNow('2026-10-03 09:14:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled'                 => true,
            'time'                    => '00:30',
            'last_scheduled_run_date' => '2026-10-03',
        ]));
    }

    public function test_due_when_the_last_success_was_yesterday(): void
    {
        Carbon::setTestNow('2026-10-03 00:30:00');

        $this->assertTrue($this->service()->dueForScheduledRun([
            'enabled'                 => true,
            'time'                    => '00:30',
            'last_scheduled_run_date' => '2026-10-02',
        ]));
    }

    public function test_missing_time_is_never_due(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => true,
        ]));
    }

    public function test_malformed_time_is_never_due(): void
    {
        Carbon::setTestNow('2026-10-03 09:00:00');

        $this->assertFalse($this->service()->dueForScheduledRun([
            'enabled' => true,
            'time'    => 'not-a-time',
        ]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Start MySQL from the XAMPP Control Panel first, then:

```bash
cd back-end
php artisan test --filter=ScheduleGateTest
```

Expected: FAIL. `test_due_hours_late_when_the_exact_minute_was_missed` fails because the current gate returns `false` for any minute other than `00:30`. `test_malformed_time_is_never_due` may error rather than fail.

- [ ] **Step 3: Replace the gate**

In `back-end/app/Services/BackupService.php`, replace the stale one-line docblock at line 154 —

```php
    /** Enabled, current minute matches the configured time, not already run today. */
```

— together with the whole `dueForScheduledRun()` method below it (lines 155-172), with:

```php
    /**
     * Is a scheduled backup owed right now?
     *
     * Deliberately "at or after", not "exactly at": this runs off a per-minute
     * scheduler tick, and an exact H:i comparison loses the entire day if the
     * machine is asleep, rebooting, or powered off for that one minute. The
     * old standalone task leaned on Task Scheduler's StartWhenAvailable for the
     * same reason -- its log shows runs landing at 09:50 and 05:12 for a 00:30
     * schedule. `last_scheduled_run_date` is what keeps a late catch-up from
     * becoming a second backup on a day already covered.
     */
    public function dueForScheduledRun(array $schedule): bool
    {
        if (empty($schedule['enabled'])) {
            return false;
        }

        $time = $schedule['time'] ?? null;
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return false;
        }

        if (($schedule['last_scheduled_run_date'] ?? null) === now()->toDateString()) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()->greaterThanOrEqualTo(now()->copy()->setTime($hour, $minute));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end
php artisan test --filter=ScheduleGateTest
```

Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Services/BackupService.php back-end/tests/Feature/Backup/ScheduleGateTest.php
git commit -m "fix(backup): catch up a missed scheduled run instead of skipping the day"
```

---

## Task 2: Retention settings plumbing

Add three retention keys to the stored schedule with defaults, validation, and a settings round-trip, before anything consumes them.

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/BackupController.php:42-50` (validation), `:116-125` (defaults)
- Test: `back-end/tests/Feature/Backup/SettingsApiTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Backup/SettingsApiTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Mirrors the makeUser()/activeStatusId() helpers in
     * tests/Feature/Audit/AuditTrailFilterTest.php:34-60 — forceCreate because the
     * users table has guarded columns and expects a non-null status.
     *
     * The status lookup MUST be check-then-create: `airkingV5_test.status_lookup`
     * ships with (1, Active, user) and (2, Inactive, user) permanently seeded, so an
     * unconditional forceCreate throws UniqueConstraintViolationException on every
     * test.
     */
    private function admin(): User
    {
        $role = Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'admin']);

        $statusId = (int) (StatusLookup::first()?->status_id ?? StatusLookup::forceCreate([
            'status_name'     => 'Active',
            'status_category' => 'user',
        ])->status_id);

        return User::forceCreate([
            'first_name'    => 'Backup',
            'last_name'     => 'Admin',
            'email'         => 'bkadmin_' . uniqid() . '@test.local',
            'username'      => 'bkadmin_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $statusId,
        ]);
    }

    public function test_settings_expose_retention_defaults(): void
    {
        SystemSetting::where('key', SystemSetting::KEY_BACKUP_SCHEDULE)->delete();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/backup/settings');

        $response->assertOk()
            ->assertJsonPath('data.schedule.keep_daily_days', 14)
            ->assertJsonPath('data.schedule.keep_weekly_weeks', 12)
            ->assertJsonPath('data.schedule.keep_monthly_months', 12);
    }

    public function test_retention_values_round_trip(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->putJson('/api/backup/settings', [
            'enabled'             => true,
            'time'                => '00:30',
            'keep_daily_days'     => 7,
            'keep_weekly_weeks'   => 4,
            'keep_monthly_months' => 6,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.keep_daily_days', 7)
            ->assertJsonPath('data.keep_weekly_weeks', 4)
            ->assertJsonPath('data.keep_monthly_months', 6);
    }

    public function test_retention_rejects_zero_and_absurd_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/backup/settings', ['keep_daily_days' => 0])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/backup/settings', ['keep_daily_days' => 4000])
            ->assertStatus(422);
    }

    public function test_next_scheduled_run_is_today_when_the_time_is_still_ahead(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-03 06:00:00');

        $response = $this->actingAs($this->admin(), 'sanctum')->putJson('/api/backup/settings', [
            'enabled' => true,
            'time'    => '23:30',
        ]);
        $response->assertOk();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/backup/settings')
            ->assertOk()
            ->assertJsonPath('data.stats.next_scheduled_run', '2026-10-03T23:30:00+00:00');

        \Illuminate\Support\Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end
php artisan test --filter=SettingsApiTest
```

Expected: FAIL. `data.schedule.keep_daily_days` is missing because no default exists; the retention PUT silently drops unvalidated keys.

- [ ] **Step 3: Add validation**

The existing rule is `'time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/'`, which accepts `"99:99"` — confirmed: `preg_match('/^\d{2}:\d{2}$/', '99:99')` is `true`. The service's gate then rejects it, so the admin saves a schedule that silently never runs. Two sources of truth for "a valid time", drifting apart. This step makes the controller the strict one, so an invalid schedule fails at save time with a 422 instead of no-opping forever.

Use `\A`/`\z` rather than `^`/`$`: PCRE's `$` matches before a trailing newline, so `"00:30\n"` passes a `$`-anchored pattern (verified — Task 1 was corrected for exactly this).

In `back-end/app/Http/Controllers/Api/BackupController.php`, replace the `$validated = $request->validate([...])` block in `updateSettings()` with:

```php
        $validated = $request->validate([
            'enabled'             => 'sometimes|boolean',
            // \A..\z, not ^..$: PCRE's $ also matches before a trailing newline,
            // which would let "00:30\n" through. Must stay in step with the same
            // pattern in BackupService::dueForScheduledRun().
            // Array form, not a single pipe-delimited string: Laravel explodes a
            // string rule list on every "|", which would also split the regex's
            // own alternation inside ([01]\d|2[0-3]) and throw
            // "preg_match(): No ending delimiter '/' found".
            'time'                => ['sometimes', 'string', 'regex:/\A([01]\d|2[0-3]):[0-5]\d\z/'],
            'frequency'           => ['sometimes', Rule::in(['daily'])],
            'storage_path'        => 'sometimes|nullable|string|max:500',
            'keep_daily_days'     => 'sometimes|integer|min:1|max:365',
            'keep_weekly_weeks'   => 'sometimes|integer|min:1|max:260',
            'keep_monthly_months' => 'sometimes|integer|min:1|max:120',
        ]);
```

Add these two cases to `SettingsApiTest` (Step 1's file) to pin it:

```php
    public function test_an_out_of_range_time_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/backup/settings', ['time' => '99:99'])
            ->assertStatus(422);
    }

    /**
     * Not a rejection: Laravel's global TrimStrings middleware (framework default,
     * not removed in bootstrap/app.php) strips the newline before validation runs,
     * so this value cannot reach the validator intact over HTTP. Asserting a 422
     * here would pin a path that does not exist. The anchored \A..\z guard still
     * earns its place in BackupService::dueForScheduledRun(), which reads the
     * stored JSON blob directly and never passes through middleware — a newline
     * can reach it from a direct DB edit or a pre-validation write.
     */
    public function test_a_time_with_a_trailing_newline_is_normalised_and_stored_trimmed(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/backup/settings', ['enabled' => true, 'time' => "00:30\n"])
            ->assertOk()
            ->assertJsonPath('data.time', '00:30');
    }
```

- [ ] **Step 4: Add defaults**

In the same file, replace the `scheduleWithDefaults()` body with:

```php
    private function scheduleWithDefaults(): array
    {
        return array_merge([
            'enabled'                 => false,
            'time'                    => '00:01',
            'frequency'               => 'daily',
            'storage_path'            => null,
            'last_scheduled_run_date' => null,
            'keep_daily_days'         => 14,
            'keep_weekly_weeks'       => 12,
            'keep_monthly_months'     => 12,
        ], SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []));
    }
```

- [ ] **Step 5: Fix `nextRunAt()` so it cannot drift**

`Carbon::createFromFormat('H:i', $time)` carries today's date *and* non-zero seconds, which makes a 23:30 schedule report `23:30:47`. Replace `nextRunAt()` with:

```php
    private function nextRunAt(?string $time): ?Carbon
    {
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));
        $today = now()->copy()->setTime($hour, $minute);

        return $today->isFuture() ? $today : $today->addDay();
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
cd back-end
php artisan test --filter=SettingsApiTest
```

Expected: PASS, 6 tests.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Http/Controllers/Api/BackupController.php back-end/tests/Feature/Backup/SettingsApiTest.php
git commit -m "feat(backup): add retention settings to the backup schedule API"
```

---

## Task 3: Retention pruning

Delete snapshot files outside the grandfather-father-son window and mark their history rows, keeping `backup_logs` as an intact audit trail.

**Files:**
- Modify: `back-end/app/Services/BackupService.php`
- Test: `back-end/tests/Feature/Backup/RetentionTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Backup/RetentionTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use App\Models\BackupLog;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Retention exists for two different reasons at once. Dailies cover "someone
 * deleted the wrong records this morning". The Sunday and 1st-of-month copies
 * cover an intrusion discovered weeks later, where every recent snapshot already
 * contains the damage.
 */
class RetentionTest extends TestCase
{
    use DatabaseTransactions;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backups-test-' . uniqid());
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Creates a snapshot file plus its history row, dated $date. */
    private function snapshot(string $date): BackupLog
    {
        $name = 'backup_' . str_replace('-', '', $date) . '_' . uniqid() . '.sql';
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, "-- snapshot\n");

        $log = BackupLog::create([
            'action'          => 'backup',
            'triggered_by'    => 'scheduled',
            'status'          => 'success',
            'file_path'       => $path,
            'file_name'       => $name,
            'file_size_bytes' => filesize($path),
        ]);

        $log->forceFill(['created_at' => $date . ' 00:30:00'])->save();

        return $log->refresh();
    }

    private function policy(): array
    {
        return [
            'keep_daily_days'     => 14,
            'keep_weekly_weeks'   => 12,
            'keep_monthly_months' => 12,
        ];
    }

    public function test_recent_dailies_are_kept(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $recent = $this->snapshot('2026-09-28');   // 5 days old

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($recent->file_path);
        $this->assertSame('success', $recent->refresh()->status);
    }

    public function test_an_ordinary_old_snapshot_is_pruned(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');      // 18 days old, a Tuesday, not the 1st
        $path = $old->file_path;

        $pruned = app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertSame(1, $pruned);
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_pruned_row_survives_as_history(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');

        app(BackupService::class)->pruneOldBackups($this->policy());

        $row = $old->refresh();
        $this->assertSame('pruned', $row->status);
        $this->assertNull($row->file_path);
        $this->assertNotNull($row->file_name, 'the name stays so history still reads sensibly');
    }

    public function test_a_sunday_snapshot_outlives_the_daily_window(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $sunday = $this->snapshot('2026-09-13');   // a Sunday, 20 days old

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($sunday->file_path);
    }

    public function test_a_first_of_month_snapshot_outlives_the_weekly_window(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $monthly = $this->snapshot('2026-03-01');  // 1st of month, ~7 months old

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($monthly->file_path);
    }

    public function test_a_sunday_beyond_the_weekly_window_is_pruned(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-05-03');      // a Sunday, ~22 weeks old, not the 1st
        $path = $old->file_path;

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileDoesNotExist($path);
    }

    public function test_a_missing_file_is_reconciled_not_counted(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');
        unlink($old->file_path);                   // someone deleted it by hand

        $pruned = app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertSame(0, $pruned, 'nothing was deleted by us');
        $this->assertSame('pruned', $old->refresh()->status);
    }

    public function test_a_corrupt_retention_value_falls_back_to_the_default_not_to_one_day(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        // 10 days old: inside the default 14-day window, outside a 1-day window.
        $recent = $this->snapshot('2026-09-23');

        app(BackupService::class)->pruneOldBackups([
            'keep_daily_days'     => 'abc',
            'keep_weekly_weeks'   => -5,
            'keep_monthly_months' => null,
        ]);

        $this->assertFileExists(
            $recent->file_path,
            'garbage retention values must fall back to the 14-day default, not clamp to 1 day'
        );
    }

    public function test_an_out_of_range_retention_value_falls_back_to_the_default(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $recent = $this->snapshot('2026-09-23');

        app(BackupService::class)->pruneOldBackups(['keep_daily_days' => 99999]);

        $this->assertFileExists($recent->file_path);
    }

    public function test_failed_rows_are_left_alone(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $failed = BackupLog::create([
            'action'        => 'backup',
            'triggered_by'  => 'scheduled',
            'status'        => 'failed',
            'error_message' => 'mysqldump exploded',
        ]);
        $failed->forceFill(['created_at' => '2026-01-01 00:30:00'])->save();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertSame('failed', $failed->refresh()->status);
    }

    public function test_restore_rows_are_never_pruned(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $restore = $this->snapshot('2026-01-05');
        $restore->forceFill(['action' => 'restore'])->save();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($restore->refresh()->file_path);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end
php artisan test --filter=RetentionTest
```

Expected: FAIL with `Call to undefined method App\Services\BackupService::pruneOldBackups()`.

- [ ] **Step 3: Implement pruning**

In `back-end/app/Services/BackupService.php`, add these three methods immediately after `dueForScheduledRun()`:

```php
    /**
     * Grandfather-father-son retention over the recorded snapshots.
     *
     * Deletes the FILE but keeps the history row, flipped to `pruned`. The row is
     * the audit trail this feature promises; deleting it would make an aged-out
     * backup indistinguishable from one that never happened.
     *
     * Driven off `backup_logs.created_at` rather than the file mtime, because a
     * file copied or restored from elsewhere carries a mtime that has nothing to
     * do with when the snapshot was taken.
     *
     * @param  array<string,mixed>  $policy
     * @return int  how many files this call actually deleted
     */
    public function pruneOldBackups(array $policy): int
    {
        $keepDailyDays     = $this->retentionWindow($policy, 'keep_daily_days', 14, 365);
        $keepWeeklyWeeks   = $this->retentionWindow($policy, 'keep_weekly_weeks', 12, 260);
        $keepMonthlyMonths = $this->retentionWindow($policy, 'keep_monthly_months', 12, 120);

        $deleted = 0;

        $candidates = BackupLog::query()
            ->where('action', 'backup')
            ->where('status', 'success')
            ->whereNotNull('file_path')
            ->orderBy('log_id')
            ->get();

        foreach ($candidates as $log) {
            if ($this->shouldKeepSnapshot($log->created_at, $keepDailyDays, $keepWeeklyWeeks, $keepMonthlyMonths)) {
                continue;
            }

            $path = (string) $log->file_path;

            if (is_file($path)) {
                if (@unlink($path)) {
                    $deleted++;
                } else {
                    // Locked by a virus scanner or an open download. Leave the row
                    // alone so the next run retries rather than losing track of a
                    // file that is still on disk.
                    continue;
                }
            }

            // Reached either by a successful delete or by the file already being
            // gone -- both mean the snapshot is no longer restorable.
            $log->forceFill([
                'status'    => 'pruned',
                'file_path' => null,
            ])->save();
        }

        return $deleted;
    }

    /**
     * One retention window, read defensively.
     *
     * Falls back to the DEFAULT on anything invalid, deliberately not to a clamped
     * minimum. `max(1, (int) $v)` looks safe and is the opposite: `(int) "abc"` is
     * 0, so `max(1, 0)` is 1, turning a corrupted settings blob into "keep only one
     * day" -- the most destructive setting available. On the one path in this
     * service that deletes files, bad input has to fail toward keeping data.
     */
    private function retentionWindow(array $policy, string $key, int $default, int $max): int
    {
        $raw = $policy[$key] ?? null;

        if (! is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;

        return ($value >= 1 && $value <= $max) ? $value : $default;
    }

    /**
     * Keep if inside the daily window, or a Sunday inside the weekly window, or a
     * 1st-of-month inside the monthly window. Outside all three it is expendable.
     */
    private function shouldKeepSnapshot(
        ?\Illuminate\Support\Carbon $takenAt,
        int $keepDailyDays,
        int $keepWeeklyWeeks,
        int $keepMonthlyMonths,
    ): bool {
        if ($takenAt === null) {
            // No date to judge by -- never destroy it.
            return true;
        }

        if ($takenAt->greaterThanOrEqualTo(now()->copy()->subDays($keepDailyDays))) {
            return true;
        }

        if ($takenAt->isSunday() && $takenAt->greaterThanOrEqualTo(now()->copy()->subWeeks($keepWeeklyWeeks))) {
            return true;
        }

        if ($takenAt->day === 1 && $takenAt->greaterThanOrEqualTo(now()->copy()->subMonths($keepMonthlyMonths))) {
            return true;
        }

        return false;
    }

    /**
     * Reads the stored policy and prunes. Convenience for the scheduled command.
     *
     * SystemSetting::getValue() hands back the raw stored string when the blob
     * does not decode, and a bare scalar when it decodes to one (`5`, `"true"`),
     * so this cannot assume it got an array. A non-array means the settings row is
     * corrupt and nothing can be trusted about the admin's intent -- prune with an
     * empty policy, which retentionWindow() turns into the documented 14/12/12
     * defaults rather than anything more destructive.
     */
    public function pruneUsingStoredPolicy(): int
    {
        $policy = SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []);

        return $this->pruneOldBackups(is_array($policy) ? $policy : []);
    }
```

- [ ] **Step 4: Allow the new status value**

`backup_logs.status` is `string(10)` so `pruned` fits with no migration. Confirm the column comment is not enforced as a constraint:

```bash
cd back-end
php artisan tinker --execute="echo json_encode(DB::select(\"SHOW COLUMNS FROM backup_logs LIKE 'status'\"));"
```

Expected: type `varchar(10)`, no enum. If it prints an enum instead, stop and add a migration widening it before continuing.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd back-end
php artisan test --filter=RetentionTest
```

Expected: 12 of your tests pass — but the run reports **17 passed**, because
`--filter=RetentionTest` also matches the pre-existing
`Tests\Feature\Reports\ReportRetentionTest` (5 tests). Confirm those 5 are still
green and that 12 of the 17 are yours.

Then confirm no regression across the whole feature:

```bash
cd back-end
php artisan test --filter=Backup
```

Expected: PASS, 30 tests (9 ScheduleGateTest + 9 SettingsApiTest + 12 RetentionTest).

- [ ] **Step 6: Commit**

```bash
git add back-end/app/Services/BackupService.php back-end/tests/Feature/Backup/RetentionTest.php
git commit -m "feat(backup): prune aged snapshots on a GFS schedule, keeping history rows"
```

### Properties of the finished engine — read before writing any further retention test

Three things are true of the shipped code that are easy to trip over later:

**1. The `ALWAYS_KEEP_NEWEST = 3` floor makes under-populated tests vacuous.** A test holding one aged snapshot has that snapshot inside the floor, so it is kept for the wrong reason. This bit seven of the original tests: they kept passing while asserting nothing — `test_a_corrupt_retention_value_falls_back_to_the_default_not_to_one_day` would have passed even if the fallback clamped to 1 day, which is the exact bug it exists to catch. The fix in `RetentionTest` is a `ballast()` helper creating three recent snapshots **after** the subject, so they hold the highest `log_id`s and absorb the floor:

```php
    /**
     * Three recent snapshots that soak up ALWAYS_KEEP_NEWEST, so the subject under
     * test is judged on its date rather than protected by the floor. Must be called
     * AFTER the subject so these hold the higher log_ids. They are days old, so
     * every policy used here keeps them and they never change a deleted-count.
     */
    private function ballast(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
            $this->snapshot($date);
        }
    }
```

Any future test asserting that something IS deleted needs ballast, or more than three snapshots. Do not add it to `test_the_newest_snapshots_are_never_pruned` (that tests the floor itself), nor to the failed-row and restore-row tests (their rows are excluded from the `protectedIds` query by `status`/`action`, so they are already non-vacuous).

**2. Idempotency now rests on `status` alone, not on `file_path`.** The reconcile-but-keep-path branch leaves `status='pruned'` with a non-null `file_path`. The candidate query filters `status='success'`, so such a row is never revisited — but `whereNotNull('file_path')` is no longer what prevents reprocessing. Relaxing the status filter would reintroduce it.

**3. The floor protects rows, not restorable files.** A row whose file was deleted outside the app occupies a protected slot until a prune reconciles it, so in the worst case all three protected rows could point at files that no longer exist. The floor is still much better than no floor, but it is not a guarantee of three restorable snapshots. Task 8's checksums are the natural place to make it one; not attempted here.

---

## Task 4: Wire pruning into the scheduled run

**Files:**
- Modify: `back-end/app/Console/Commands/RunScheduledBackup.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Backup/ScheduledCommandTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use App\Models\BackupLog;
use App\Models\SystemSetting;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class ScheduledCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_successful_scheduled_run_also_prunes(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');

        SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, [
            'enabled' => true,
            'time'    => '00:30',
        ]);

        $log = new BackupLog(['status' => 'success', 'file_name' => 'backup_x.sql']);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('dueForScheduledRun')->once()->andReturn(true);
        $service->shouldReceive('createBackup')->once()->with(null, 'scheduled')->andReturn($log);
        $service->shouldReceive('pruneUsingStoredPolicy')->once()->andReturn(2);
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup complete')
            ->expectsOutputToContain('Pruned 2')
            ->assertExitCode(0);
    }

    public function test_a_failed_scheduled_run_does_not_prune(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');

        SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, [
            'enabled' => true,
            'time'    => '00:30',
        ]);

        $log = new BackupLog(['status' => 'failed', 'error_message' => 'mysqldump exploded']);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('dueForScheduledRun')->once()->andReturn(true);
        $service->shouldReceive('createBackup')->once()->andReturn($log);
        $service->shouldNotReceive('pruneUsingStoredPolicy');
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')->assertExitCode(1);
    }

    public function test_a_run_that_is_not_due_does_nothing(): void
    {
        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('dueForScheduledRun')->once()->andReturn(false);
        $service->shouldNotReceive('createBackup');
        $service->shouldNotReceive('pruneUsingStoredPolicy');
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Not due')
            ->assertExitCode(0);
    }

    /**
     * A lock conflict during prune must not retroactively fail a backup that
     * already succeeded. pruneOldBackups() takes the service mutex internally, so
     * a manual backup or restore running concurrently throws here — and retention
     * is not urgent enough to be worth reporting as a failed run.
     */
    public function test_a_lock_conflict_during_prune_is_a_skip_not_a_failure(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');

        SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, [
            'enabled' => true,
            'time'    => '00:30',
        ]);

        $log = new BackupLog(['status' => 'success', 'file_name' => 'backup_x.sql']);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('dueForScheduledRun')->once()->andReturn(true);
        $service->shouldReceive('createBackup')->once()->andReturn($log);
        $service->shouldReceive('pruneUsingStoredPolicy')->once()
            ->andThrow(new \App\Exceptions\BackupInProgressException());
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup complete')
            ->expectsOutputToContain('Prune skipped')
            ->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end
php artisan test --filter=ScheduledCommandTest
```

Expected: FAIL — `pruneUsingStoredPolicy` is never called, and no "Pruned 2" output exists.

- [ ] **Step 3: Call prune after a successful backup**

In `back-end/app/Console/Commands/RunScheduledBackup.php`, replace the `if ($log->status === 'success') { ... }` block with:

```php
        if ($log->status === 'success') {
            // Only mark today "done" on success — a transient failure (disk
            // full, mysqldump hiccup) should retry on the next minute's tick
            // rather than silently going quiet until tomorrow.
            $schedule = array_merge($schedule, ['last_scheduled_run_date' => now()->toDateString()]);
            SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, $schedule);

            $this->info("Backup complete: {$log->file_name}");

            // Prune only after a success, so a run of failures can never age out
            // the last good snapshots and leave nothing restorable behind.
            //
            // NOT wrapped in a lock here. pruneOldBackups() takes the service's own
            // non-reentrant mutex internally, exactly as createBackup() just did and
            // released. Holding it around both would block for LOCK_WAIT_SECONDS and
            // then throw -- the same deadlock restore() documents when it calls
            // performBackup() directly rather than createBackup().
            try {
                $pruned = $service->pruneUsingStoredPolicy();
                if ($pruned > 0) {
                    $this->line("Pruned {$pruned} aged snapshot(s).");
                }
            } catch (BackupInProgressException $e) {
                // A manual backup or restore holds the lock. Retention is not
                // urgent and the next tick retries, so this is a skip, not a
                // failure -- the backup itself already succeeded.
                $this->line('Prune skipped: another backup operation is in progress.');
            } catch (\Throwable $e) {
                // A prune failure must not turn a good backup into a failed run.
                $this->warn('Prune failed: ' . $e->getMessage());
            }

            return self::SUCCESS;
        }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd back-end
php artisan test --filter=ScheduledCommandTest
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add back-end/app/Console/Commands/RunScheduledBackup.php back-end/tests/Feature/Backup/ScheduledCommandTest.php
git commit -m "feat(backup): prune after each successful scheduled backup"
```

---

## Task 5: Admin UI — retention fields and an honest timezone label

The admin must be able to set retention, and must not be able to misread the time field. `config/app.php` is UTC and stays UTC — changing it would reinterpret every stored timestamp in the database.

**Files:**
- Modify: `frontend/src/admin/components/BackupPanel.jsx`

- [ ] **Step 1: Add retention state**

In `frontend/src/admin/components/BackupPanel.jsx`, after the `const [storagePath, setStoragePath] = useState('');` line, add:

```jsx
  const [keepDailyDays, setKeepDailyDays] = useState(14);
  const [keepWeeklyWeeks, setKeepWeeklyWeeks] = useState(12);
  const [keepMonthlyMonths, setKeepMonthlyMonths] = useState(12);
```

- [ ] **Step 2: Load them in `loadAll`**

In the same file, immediately after `setStoragePath(schedule.storage_path || '');` inside `loadAll`, add:

```jsx
      setKeepDailyDays(schedule.keep_daily_days ?? 14);
      setKeepWeeklyWeeks(schedule.keep_weekly_weeks ?? 12);
      setKeepMonthlyMonths(schedule.keep_monthly_months ?? 12);
```

- [ ] **Step 3: Send them on save**

Replace the `backupAPI.updateSettings({...})` call inside `handleSaveSchedule` with:

```jsx
      await backupAPI.updateSettings({
        enabled,
        time,
        frequency: 'daily',
        storage_path: storagePath.trim() || null,
        keep_daily_days: Number(keepDailyDays) || 14,
        keep_weekly_weeks: Number(keepWeeklyWeeks) || 12,
        keep_monthly_months: Number(keepMonthlyMonths) || 12,
      });
```

- [ ] **Step 4: Label the time field with the real timezone**

Replace the whole "Backup time" `bp-field` block with:

```jsx
          <div className="bp-field">
            <label htmlFor="bp-time">
              Backup time ({stats.server_timezone || 'UTC'})
            </label>
            <input
              id="bp-time"
              type="time"
              value={time}
              onChange={(e) => setTime(e.target.value)}
            />
            <small className="bp-hint">
              Server time, not your local time. Server clock now:{' '}
              {formatWhen(stats.server_time)}
            </small>
          </div>
```

- [ ] **Step 5: Add the retention fields**

Immediately after the "Backup storage path" `bp-field` block, insert:

```jsx
          <div className="bp-field">
            <label htmlFor="bp-keep-daily">Keep daily snapshots for (days)</label>
            <input
              id="bp-keep-daily"
              type="number"
              min="1"
              max="365"
              value={keepDailyDays}
              onChange={(e) => setKeepDailyDays(e.target.value)}
            />
          </div>

          <div className="bp-field">
            <label htmlFor="bp-keep-weekly">Keep Sunday snapshots for (weeks)</label>
            <input
              id="bp-keep-weekly"
              type="number"
              min="1"
              max="260"
              value={keepWeeklyWeeks}
              onChange={(e) => setKeepWeeklyWeeks(e.target.value)}
            />
          </div>

          <div className="bp-field">
            <label htmlFor="bp-keep-monthly">Keep 1st-of-month snapshots for (months)</label>
            <input
              id="bp-keep-monthly"
              type="number"
              min="1"
              max="120"
              value={keepMonthlyMonths}
              onChange={(e) => setKeepMonthlyMonths(e.target.value)}
            />
            <small className="bp-hint">
              Weekly and monthly copies are what let you go back past an intrusion
              discovered weeks later, when every recent snapshot already contains
              the damage.
            </small>
          </div>
```

- [ ] **Step 6: Style the hint**

Append to `frontend/src/admin/styles/backup_panel.css`:

```css
.bp-hint {
  display: block;
  margin-top: 4px;
  font-size: 0.78rem;
  line-height: 1.4;
  opacity: 0.72;
}
```

- [ ] **Step 7: Show `pruned` as its own badge**

`StatusBadge` (`frontend/src/admin/components/BackupPanel.jsx:34`) renders `status` uppercased into a `bp-badge--<status>` class, so a pruned row already reads `PRUNED` but with no styling behind it. Behaviour is already correct — the download and restore controls gate on `status === 'success'`, and the API agrees (`BackupController::download()` and `restore()` both reject a non-success row) — so this is styling only.

Append to `frontend/src/admin/styles/backup_panel.css`:

```css
.bp-badge--pruned {
  background: rgba(130, 130, 130, 0.18);
  color: #6b7280;
}
```

- [ ] **Step 7b: Correct the stale column comment**

`backup_logs.status` carries a `->comment('success | failed')` from its original migration (`back-end/database/migrations/2026_09_18_000001_create_backup_logs_table.php:23`), which no longer lists every value the column holds. The comment is not a constraint and `varchar(10)` fits `pruned`, so this is documentation only — but a stale comment on a column whose values drive UI gating is worth a line.

Do NOT edit the original migration (it has already run on both databases). Add a new one, `back-end/database/migrations/2026_10_03_000002_note_pruned_backup_status.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Comment-only. Retention added a third value, `pruned`, which the original
     * migration's "success | failed" comment predates. No type change: the column
     * is already varchar(10) and was never an enum, so no data is touched.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
            . "COMMENT 'success | failed | pruned'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
            . "COMMENT 'success | failed'"
        );
    }
};
```

Run it on both databases:

```bash
cd back-end
php artisan migrate --force
```

```powershell
cd back-end
$env:DB_DATABASE='airkingV5_test'; php artisan migrate --force; Remove-Item Env:\DB_DATABASE
```

Confirm the column is unchanged apart from the comment:

```bash
/c/xampp/mysql/bin/mysql.exe -uroot -h127.0.0.1 -e "SHOW FULL COLUMNS FROM airkingvv6.backup_logs LIKE 'status'"
```

Expected: `varchar(10)`, `NO`, default `success`, comment `success | failed | pruned`.

- [ ] **Step 8: Build and eyeball it**

```bash
cd frontend
npm run build
```

Expected: build succeeds with no new warnings. Then start MySQL and Apache, open `/admin/backup`, and confirm: three retention inputs render with 14/12/12, the time label reads "Backup time (UTC)", and the hint shows the server clock.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/admin/components/BackupPanel.jsx frontend/src/admin/styles/backup_panel.css
git commit -m "feat(backup): admin retention controls and an explicit server-timezone label"
```

---

## Task 6: The hidden scheduler task

One Windows task calling `schedule:run` every minute, with no visible window. This replaces the popup task and revives five other scheduled jobs that have never run.

**Files:**
- Create: `scripts/install-scheduler-task.ps1`

- [ ] **Step 1: Write the installer script**

Create `scripts/install-scheduler-task.ps1`:

```powershell
<#
  Registers the single Windows task that drives every Laravel scheduled job
  (backup, auto-archive, warranty expiry, retention cleanup, restock analytics,
  ledger drift check).

  Laravel's scheduler is not self-running: it needs the OS to call
  `schedule:run` every minute, and Laravel then decides what is actually due.
  Without this task, every Schedule:: entry in routes/console.php is dead code.

  Hidden + ServiceAccount so no console window ever appears and nothing can be
  closed mid-dump. The old AirkingInventory-NightlyBackup task ran Interactive
  and visible, which is why a terminal used to flash on screen -- and one run
  was killed by the window being closed (result 0xC000013A).

  Run this from an ELEVATED PowerShell:
    powershell -ExecutionPolicy Bypass -File scripts\install-scheduler-task.ps1
#>

$ErrorActionPreference = 'Stop'

$TaskName = 'AirkingInventory-Scheduler'
$Php      = 'C:\xampp\php\php.exe'
$Artisan  = 'C:\xampp\htdocs\Airking_Inventory\back-end\artisan'

if (-not (Test-Path $Php))     { throw "php.exe not found at $Php" }
if (-not (Test-Path $Artisan)) { throw "artisan not found at $Artisan" }

$action = New-ScheduledTaskAction -Execute $Php -Argument "`"$Artisan`" schedule:run"

# Every minute, indefinitely. Laravel gates what actually runs.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration ([TimeSpan]::MaxValue)

$settings = New-ScheduledTaskSettingsSet `
    -Hidden `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2)

# SYSTEM so it runs with no window and whether or not anyone is logged in.
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName `
    -Action $action -Trigger $trigger -Settings $settings -Principal $principal `
    -Description 'Drives all Laravel scheduled jobs for Airking Inventory (runs every minute, hidden).' `
    -Force | Out-Null

Write-Output "Registered '$TaskName' -- runs every minute, hidden, as SYSTEM."
Write-Output 'Verify with:'
Write-Output "  Get-ScheduledTaskInfo -TaskName '$TaskName' | Select LastRunTime, LastTaskResult, NextRunTime"
```

- [ ] **Step 2: Confirm the scheduler sees all six jobs**

```bash
cd back-end
php artisan schedule:list
```

Expected: six entries — `analytics:restock`, `archive:auto`, `warranties:expire`, `archive:cleanup`, `backup:run`, `ledger:drift-check`. If `backup:run` is absent, stop; nothing downstream will work.

- [ ] **Step 3: Prove a scheduled backup works end to end, by hand**

Start MySQL from the XAMPP Control Panel, then:

```bash
cd back-end
php artisan backup:run --force
```

Expected: `Backup complete: backup_<timestamp>_<rand>.sql`. Confirm the file exists:

```bash
ls -la back-end/storage/app/backups
```

Expected: one `.sql` file, non-zero size. If this fails, fix it before registering any task — a task that runs broken code on a timer just fails silently every minute.

- [ ] **Step 4: Register the task**

This needs elevation, so the user runs it. Ask them to paste into the Claude Code prompt:

```
! powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\Airking_Inventory\scripts\install-scheduler-task.ps1
```

Expected: `Registered 'AirkingInventory-Scheduler' -- runs every minute, hidden, as SYSTEM.`

- [ ] **Step 5: Verify it ticks, invisibly**

Wait two minutes, then:

```bash
powershell -Command "Get-ScheduledTaskInfo -TaskName 'AirkingInventory-Scheduler' | Select-Object LastRunTime, LastTaskResult, NextRunTime | Format-List"
```

Expected: `LastTaskResult: 0`, `LastRunTime` within the last minute. No window should have appeared at any point. Then confirm Laravel is being reached:

```bash
ls -la back-end/storage/logs/backup-run.log
```

Expected: the file now exists and contains `Not due — skipping.` lines.

- [ ] **Step 6: Commit**

```bash
git add scripts/install-scheduler-task.ps1
git commit -m "feat(ops): hidden Windows task to drive all Laravel scheduled jobs"
```

---

## Task 7: Prerequisite — MySQL as an auto-start service

A 00:30 schedule is meaningless if MySQL only runs while the XAMPP Control Panel is open. This is why every run of the old script since 14 Sept failed with `Can't connect to MySQL server on '127.0.0.1' (10061)`.

- [ ] **Step 1: Confirm the gap**

```bash
powershell -Command "Get-Service | Where-Object { $_.Name -match 'mysql|maria' } | Select-Object Name, Status, StartType"
```

Expected right now: no output — no MySQL service exists.

- [ ] **Step 2: Have the user install the service**

This needs elevation and a GUI. Ask the user to:

1. Close the XAMPP Control Panel.
2. Reopen it **as Administrator** (right-click → Run as administrator).
3. Tick the checkbox in the leftmost **Service** column on the **MySQL** row.
4. Confirm the module shows a green tick.

- [ ] **Step 3: Verify**

```bash
powershell -Command "Get-Service | Where-Object { $_.Name -match 'mysql|maria' } | Select-Object Name, Status, StartType"
```

Expected: one service, `Status: Running`, `StartType: Automatic`.

- [ ] **Step 4: Verify it survives a reboot**

Ask the user to reboot, then without opening XAMPP:

```bash
powershell -Command "& 'C:\xampp\mysql\bin\mysql.exe' -u root -N -e 'select 1'"
```

Expected: `1`. If this fails, scheduled backups will keep failing and there is no point continuing.

- [ ] **Step 5: Confirm a real unattended backup**

Set the schedule via the admin UI to two minutes in the future (remember: **UTC**), then wait three minutes and check:

```bash
cd back-end
tail -5 storage/logs/backup-run.log
ls -la storage/app/backups
```

Expected: a `Backup complete:` line and a new `.sql` file that you did not click to create. **This is the acceptance test for the whole plan.** Do not proceed to Task 9 until you have seen it.

---

## Task 8: Integrity — completeness trailer and SHA-256

Two defects a dump can carry that the current zero-byte check misses: it was **truncated** when written (disk full, process killed), or it **rotted** afterwards (bit rot, a half-finished cloud sync, a bad USB copy). Both produce a file that opens fine, looks like SQL, and fails halfway through a restore — at the exact moment there is no alternative. The old script caught the first with mysqldump's `Dump completed` trailer and the second with a `.sha256` sidecar.

Run this task **before** Task 9. Retiring the old script is the last thing that happens.

**Files:**
- Create: `back-end/database/migrations/2026_10_03_000001_add_checksum_to_backup_logs.php`
- Modify: `back-end/app/Services/BackupService.php`
- Modify: `frontend/src/admin/components/BackupPanel.jsx`
- Test: `back-end/tests/Feature/Backup/IntegrityTest.php`

- [ ] **Step 1: Write the migration**

Create `back-end/database/migrations/2026_10_03_000001_add_checksum_to_backup_logs.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            // SHA-256 is always 64 hex chars, so char() rather than varchar().
            // Nullable because every row written before this migration has no
            // checksum, and because a restore row never has a file of its own.
            $table->char('checksum_sha256', 64)->nullable()->after('file_size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropColumn('checksum_sha256');
        });
    }
};
```

- [ ] **Step 2: Run the migration on both databases**

`.env` has `APP_ENV=production`, so `--force` is required. The test database is separate and must be migrated too, or every test in this task fails on a missing column.

There is no second connection and no `.env.testing`: `phpunit.xml:26-31` overrides the *same* `mysql` connection to point at `airkingV5_test`, and those `<env>` entries apply only under PHPUnit — `--env=testing` will not pick them up. Override the database name on the command line instead.

Live database:

```bash
cd back-end
php artisan migrate --force
```

Test database (PowerShell, because the env var must be set for the child process):

```powershell
cd back-end
$env:DB_DATABASE='airkingV5_test'; php artisan migrate --force; Remove-Item Env:\DB_DATABASE
```

Verify on both:

```bash
cd back-end
/c/xampp/mysql/bin/mysql.exe -uroot -h127.0.0.1 -e "SHOW COLUMNS FROM airkingvv6.backup_logs LIKE 'checksum%'; SHOW COLUMNS FROM airkingV5_test.backup_logs LIKE 'checksum%';"
```

Expected: one row from each, `Type: char(64)`, `Null: YES`.

- [ ] **Step 3: Add `checksum_sha256` to the model's fillable**

Check `back-end/app/Models/BackupLog.php` for a `$fillable` array. If one exists and lists the columns explicitly, add `'checksum_sha256'` to it. If the model uses `$guarded = []`, no change is needed. Record which it was — the code in Step 6 uses `BackupLog::create()` and will silently drop the value if the column is not fillable.

- [ ] **Step 4: Write the failing test**

Create `back-end/tests/Feature/Backup/IntegrityTest.php`:

```php
<?php

namespace Tests\Feature\Backup;

use App\Models\BackupLog;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A corrupted dump is silent: it still opens, still looks like SQL, still has a
 * plausible size. You find out when a restore dies halfway through — which is
 * always the moment you have no alternative left. These two checks turn
 * "probably fine" into a yes/no answer.
 */
class IntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backups-integrity-' . uniqid());
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $contents): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'dump_' . uniqid() . '.sql';
        file_put_contents($path, $contents);

        return $path;
    }

    // ── completeness trailer ──────────────────────────────────

    public function test_a_mysqldump_trailer_counts_as_complete(): void
    {
        $path = $this->write("CREATE TABLE a (id int);\n-- Dump completed on 2026-10-03  0:30:01\n");

        $this->assertTrue(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_the_php_dump_trailer_counts_as_complete(): void
    {
        $path = $this->write("INSERT INTO a VALUES (1);\nSET FOREIGN_KEY_CHECKS=1;\n-- AirKing dump completed\n");

        $this->assertTrue(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_a_dump_with_no_trailer_is_incomplete(): void
    {
        // Exactly what a run killed by a full disk leaves behind.
        $path = $this->write("CREATE TABLE a (id int);\nINSERT INTO a VALUES (1),(2),(");

        $this->assertFalse(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_a_trailer_buried_far_from_the_end_does_not_count(): void
    {
        // Guards against matching the phrase inside row data rather than the
        // actual trailer: only the tail of the file is inspected.
        $path = $this->write(
            "-- Dump completed on 2026-01-01\n"
            . str_repeat("INSERT INTO notes VALUES ('x');\n", 200)
        );

        $this->assertFalse(app(BackupService::class)->dumpLooksComplete($path));
    }

    public function test_a_missing_file_is_not_complete(): void
    {
        $this->assertFalse(
            app(BackupService::class)->dumpLooksComplete($this->dir . '/nope.sql')
        );
    }

    // ── checksum ──────────────────────────────────────────────

    public function test_the_checksum_matches_php_hash_file(): void
    {
        $path = $this->write("-- Dump completed\n");

        $this->assertSame(
            hash_file('sha256', $path),
            app(BackupService::class)->checksumFor($path)
        );
    }

    public function test_verification_passes_for_an_untouched_file(): void
    {
        $path = $this->write("-- Dump completed\n");
        $log = BackupLog::create([
            'action'          => 'backup',
            'triggered_by'    => 'manual',
            'status'          => 'success',
            'file_path'       => $path,
            'file_name'       => basename($path),
            'checksum_sha256' => hash_file('sha256', $path),
        ]);

        $this->assertNull(app(BackupService::class)->verifyChecksum($log));
    }

    public function test_verification_fails_once_a_byte_changes(): void
    {
        $path = $this->write("-- Dump completed\n");
        $log = BackupLog::create([
            'action'          => 'backup',
            'triggered_by'    => 'manual',
            'status'          => 'success',
            'file_path'       => $path,
            'file_name'       => basename($path),
            'checksum_sha256' => hash_file('sha256', $path),
        ]);

        file_put_contents($path, "-- Dump completed\n-- tampered\n");

        $message = app(BackupService::class)->verifyChecksum($log);

        $this->assertNotNull($message);
        $this->assertStringContainsString('checksum', strtolower($message));
    }

    public function test_a_row_with_no_recorded_checksum_is_allowed_through(): void
    {
        // Snapshots taken before this migration have no checksum. Refusing to
        // restore them would make an upgrade destroy access to every existing
        // backup, which is worse than the risk it avoids.
        $path = $this->write("-- Dump completed\n");
        $log = BackupLog::create([
            'action'       => 'backup',
            'triggered_by' => 'manual',
            'status'       => 'success',
            'file_path'    => $path,
            'file_name'    => basename($path),
        ]);

        $this->assertNull(app(BackupService::class)->verifyChecksum($log));
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

```bash
cd back-end
php artisan test --filter=IntegrityTest
```

Expected: FAIL with `Call to undefined method App\Services\BackupService::dumpLooksComplete()`.

- [ ] **Step 6: Add the three integrity methods**

In `back-end/app/Services/BackupService.php`, add immediately after `pruneUsingStoredPolicy()`:

```php
    /**
     * How much of the file's tail to inspect for a completion trailer. Generous
     * enough for mysqldump's multi-line footer, small enough that the phrase
     * cannot be matched inside ordinary row data earlier in the dump.
     */
    private const TRAILER_WINDOW_BYTES = 512;

    /**
     * Did the writer finish?
     *
     * A dump killed partway through (full disk, power cut, killed process) is
     * indistinguishable from a good one by size alone — it parses, it looks like
     * SQL, and it restores as a half-empty database. Both of this service's dump
     * paths now end with a trailer, and its absence is the signal.
     */
    public function dumpLooksComplete(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $window = min($size, self::TRAILER_WINDOW_BYTES);
            fseek($handle, -$window, SEEK_END);
            $tail = (string) fread($handle, $window);
        } finally {
            fclose($handle);
        }

        // mysqldump writes the first; runPhpDump() writes the second.
        return str_contains($tail, 'Dump completed')
            || str_contains($tail, 'AirKing dump completed');
    }

    /** SHA-256 of the file on disk, lowercase hex. */
    public function checksumFor(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new \RuntimeException("Could not checksum backup file: {$path}");
        }

        return $hash;
    }

    /**
     * Confirms a stored snapshot is byte-for-byte what was written.
     *
     * @return string|null  null when it verifies (or predates checksums), an
     *                      operator-facing reason when it does not.
     */
    public function verifyChecksum(BackupLog $log): ?string
    {
        $recorded = $log->checksum_sha256;

        // Snapshots taken before the checksum column existed. Refusing these
        // would make upgrading destroy access to every backup already on disk.
        if (! $recorded) {
            return null;
        }

        if (! $log->file_path || ! is_file($log->file_path)) {
            return 'Backup file no longer exists on disk';
        }

        $actual = $this->checksumFor($log->file_path);

        if (! hash_equals($recorded, $actual)) {
            return 'Integrity check failed: the file on disk no longer matches the '
                . 'checksum recorded when this backup was taken. It has been altered '
                . 'or corrupted since, and restoring from it is unsafe.';
        }

        return null;
    }
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
cd back-end
php artisan test --filter=IntegrityTest
```

Expected: FAIL still — 5 trailer tests pass, but `test_the_php_dump_trailer_counts_as_complete` fails because `runPhpDump()` does not yet write a trailer, and the three checksum tests pass. Fix the dump path in the next step.

- [ ] **Step 8: Give the PHP dump path a trailer**

`runPhpDump()` currently ends with `SET FOREIGN_KEY_CHECKS=1;` and no completion marker, so a truncated PDO dump would be indistinguishable from a whole one. In `back-end/app/Services/BackupService.php`, find this line inside `runPhpDump()`:

```php
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
```

and replace it with:

```php
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            // Last thing written, deliberately. dumpLooksComplete() treats the
            // absence of this line as proof the writer died partway through.
            fwrite($handle, "-- AirKing dump completed\n");
```

- [ ] **Step 9: Reject an incomplete dump and record the checksum**

In `performBackup()`, replace this block:

```php
        if (! is_file($filePath) || filesize($filePath) === 0) {
            return $this->recordFailure('backup', $trigger, $user, 'mysqldump produced no output file', $start);
        }
```

with:

```php
        if (! is_file($filePath) || filesize($filePath) === 0) {
            return $this->recordFailure('backup', $trigger, $user, 'mysqldump produced no output file', $start);
        }

        // A half-written dump restores as a half-empty database, so it is worse
        // than no backup at all: it looks like protection you do not have.
        if (! $this->dumpLooksComplete($filePath)) {
            @unlink($filePath);

            return $this->recordFailure(
                'backup',
                $trigger,
                $user,
                'Dump is incomplete (no completion trailer) — refusing to keep it. '
                . 'Usually a full disk or a killed process.',
                $start,
            );
        }
```

Then, in the same method, add the checksum to the `BackupLog::create([...])` call — insert this line immediately after `'file_size_bytes'  => filesize($filePath),`:

```php
            'checksum_sha256'      => $this->checksumFor($filePath),
```

- [ ] **Step 10: Verify before restoring**

In `restore()`, the existing file-exists guard runs before the safety backup. Replace this block:

```php
            if (! $snapshot->file_path || ! is_file($snapshot->file_path)) {
                return $this->recordFailure('restore', 'manual', $user, 'Backup file no longer exists on disk', $start, $snapshot->log_id);
            }
```

with:

```php
            if (! $snapshot->file_path || ! is_file($snapshot->file_path)) {
                return $this->recordFailure('restore', 'manual', $user, 'Backup file no longer exists on disk', $start, $snapshot->log_id);
            }

            // Checked before the safety backup, not after: there is no reason to
            // spend a full dump on a restore that is already known to be unsafe.
            if ($problem = $this->verifyChecksum($snapshot)) {
                return $this->recordFailure('restore', 'manual', $user, $problem, $start, $snapshot->log_id);
            }
```

- [ ] **Step 11: Run the full integrity suite**

```bash
cd back-end
php artisan test --filter=IntegrityTest
```

Expected: PASS, 9 tests.

- [ ] **Step 12: Confirm a real backup now carries a checksum**

Start MySQL, then:

```bash
cd back-end
php artisan backup:run --force
php artisan tinker --execute="\$l = App\Models\BackupLog::latest('log_id')->first(); echo \$l->file_name, ' ', \$l->checksum_sha256, PHP_EOL; echo hash_file('sha256', \$l->file_path), PHP_EOL;"
```

Expected: two identical 64-character hex strings. Then confirm the trailer is really there:

```bash
tail -3 "$(ls -t back-end/storage/app/backups/*.sql | head -1)"
```

Expected: a `-- Dump completed on …` line (mysqldump path) or `-- AirKing dump completed` (PDO path).

- [ ] **Step 13: Surface it in the UI**

In `frontend/src/admin/components/BackupPanel.jsx`, add a short checksum to the history table so an operator can eyeball it. In `BackupController::transformLog()` (`back-end/app/Http/Controllers/Api/BackupController.php`), add this line to the returned array after `'file_size_bytes'`:

```php
            'checksum_short'   => $log->checksum_sha256 ? substr($log->checksum_sha256, 0, 12) : null,
```

Then in the "Backup safety" card in `BackupPanel.jsx`, add a fourth bullet to the `bp-safety` list:

```jsx
            <li><strong>Integrity</strong> — every snapshot records a SHA-256 and is
            verified before any restore. A dump without its completion trailer is
            rejected rather than kept.</li>
```

- [ ] **Step 14: Document the limit honestly**

Task 9 Step 4 rewrites `scripts/README-backup.md` and already contains the `## Integrity` section this task's behaviour needs — no extra writing here. Read that section now and confirm it matches what you actually built: a checksum recorded per snapshot, verified before restore, pre-migration snapshots allowed through, and the honest limit stated (the hash lives in `backup_logs`, the same database the dump came from, so it is corruption detection rather than tamper-proofing). If your implementation diverged from any of those four claims, correct the README text in Task 9 rather than leaving it overclaiming — the old README's *"prove the file was not altered"* is exactly the wording to avoid.

- [ ] **Step 15: Build the frontend**

```bash
cd frontend
npm run build
```

Expected: build succeeds. Open `/admin/backup` and confirm the new safety bullet renders.

- [ ] **Step 16: Commit**

```bash
git add back-end/database/migrations/2026_10_03_000001_add_checksum_to_backup_logs.php \
        back-end/app/Services/BackupService.php \
        back-end/app/Http/Controllers/Api/BackupController.php \
        back-end/app/Models/BackupLog.php \
        back-end/tests/Feature/Backup/IntegrityTest.php \
        frontend/src/admin/components/BackupPanel.jsx
git commit -m "feat(backup): reject truncated dumps and verify a SHA-256 before restoring"
```

---

## Task 9: Retire the old script

Only after Task 7 Step 5 has produced an unattended backup. Deleting this first would leave the install with no automatic backup at all.

**Files:**
- Delete: `scripts/backup-db.ps1`
- Rewrite: `scripts/README-backup.md`
- Modify: `DEPLOYMENT.md:245`

- [ ] **Step 1: Preserve the last known-good snapshots**

The old task's output in `C:\AirkingBackups` is not referenced by `backup_logs` and the app cannot see it. The newest is `airkingV5-20260909-003132.zip`, and it is of `airkingV5`, a database that no longer exists. Leave the folder in place — do not delete it as part of this work. Confirm what is there and tell the user:

```bash
powershell -Command "Get-ChildItem 'C:\AirkingBackups' -Filter *.zip | Sort-Object LastWriteTime -Descending | Select-Object -First 5 Name, LastWriteTime"
```

- [ ] **Step 2: Unregister the popup task**

```bash
powershell -Command "Unregister-ScheduledTask -TaskName 'AirkingInventory-NightlyBackup' -Confirm:$false"
```

Verify it is gone:

```bash
powershell -Command "Get-ScheduledTask -TaskName 'AirkingInventory-NightlyBackup' -ErrorAction SilentlyContinue | Measure-Object | Select-Object Count"
```

Expected: `Count: 0`.

- [ ] **Step 3: Delete the script**

```bash
git rm scripts/backup-db.ps1
```

- [ ] **Step 4: Rewrite the operator README**

Replace `scripts/README-backup.md` entirely with:

```markdown
# Database backup

Backups are configured in the app: **Admin → Backup**. There is no separate
backup script any more — `scripts/backup-db.ps1` and the
`AirkingInventory-NightlyBackup` task were removed once the in-app feature
could run unattended, because two systems configured in two places had already
drifted onto two different databases.

| | |
|---|---|
| Configure | Admin → Backup (enabled, time, storage path, retention) |
| Engine | `back-end/app/Services/BackupService.php` |
| Scheduled entry point | `php artisan backup:run` |
| Driven by | Windows task `AirkingInventory-Scheduler` → `php artisan schedule:run`, every minute, hidden |
| Default output | `back-end/storage/app/backups/` |
| History | the Backup page, and the `backup_logs` table |
| Log | `back-end/storage/logs/backup-run.log` |

## The two things that make the schedule actually fire

**MySQL must be a Windows service.** If MySQL only runs while the XAMPP Control
Panel is open, a 00:30 backup cannot connect and fails. Every run of the old
script between 14 Sept and 1 Oct 2026 failed this way. Install the service from
an elevated XAMPP Control Panel, Service column, MySQL row.

**The scheduler task must exist.** Laravel's scheduler does not run itself.
Register it with:

```powershell
# elevated PowerShell
powershell -ExecutionPolicy Bypass -File scripts\install-scheduler-task.ps1
```

The same task also drives auto-archive, warranty expiry, retention cleanup,
restock analytics, and the ledger drift check. Before it existed, none of them
had ever run.

## Times are in the server timezone

`config/app.php` sets `'timezone' => 'UTC'`, so `00:30` on the Backup page means
00:30 UTC — 08:30 Philippine time. The field is labelled with the active
timezone and shows the current server clock beside it. Do not "fix" this by
changing `app.timezone`: every timestamp already stored in the database was
written under UTC, and changing it would silently reinterpret all of them.

## Retention

Three windows, all set in the UI:

- **Dailies** (default 14 days) — the ordinary case: someone deleted the wrong
  records this morning.
- **Sunday copies** (default 12 weeks) and **1st-of-month copies** (default 12
  months) — the intrusion case. Attacks are typically found weeks after they
  start, and by then the damage is inside every recent snapshot. These are what
  give you a pre-intrusion restore point.

Pruning deletes the file but keeps the `backup_logs` row, flipped to `pruned`,
so an aged-out backup stays distinguishable from one that never happened. It
runs only after a *successful* backup, so a run of failures can never age out
your last good snapshots.

## Integrity

Every snapshot records a SHA-256, and a restore verifies it first — a file that
no longer matches is refused rather than replayed into your live database. This
reliably catches accidental corruption: bit rot, a truncated cloud sync, a bad
USB copy, a disk developing bad sectors.

It is **not** proof against a deliberate attacker. The checksum lives in
`backup_logs`, in the same database the snapshot was taken from, so anyone who
can rewrite the dump can rewrite the recorded hash too. It becomes real tamper
evidence only once a copy of the hash lives somewhere the dump does not.

Snapshots are also rejected at creation if they lack a completion trailer, which
is what a run killed by a full disk or a power cut leaves behind.

Verify one by hand:

```powershell
$log = 'backup_2026-10-03_003000_ab12cd.sql'
(Get-FileHash "back-end\storage\app\backups\$log" -Algorithm SHA256).Hash
# compare against backup_logs.checksum_sha256 for that row (case-insensitive)
```

Snapshots taken before the checksum column existed have none, and restore
without a check — refusing them would have made the upgrade destroy access to
every backup already on disk.

## Check that it is still working

Open Admin → Backup and look at **Last success**. If it is older than a day with
the schedule enabled, something is wrong. Then, in order:

```powershell
Get-ScheduledTaskInfo -TaskName 'AirkingInventory-Scheduler' |
  Select-Object LastRunTime, LastTaskResult, NextRunTime
```

`LastTaskResult` of `0` means the tick is firing. If it is, the problem is
inside Laravel:

```powershell
Get-Content back-end\storage\logs\backup-run.log -Tail 20
```

## Restore

From the UI: Admin → Backup → history row → Restore. A fresh safety backup is
taken first and the restore aborts if that fails, so a restore always has its
own undo path. Every attempt is logged.

By hand, into a scratch database first — never straight over the live one:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE airking_restore_test"
C:\xampp\mysql\bin\mysql.exe -u root airking_restore_test < back-end\storage\app\backups\backup_2026-10-03_003000_ab12cd.sql
```

Do a practice restore once now, and again every few months. A backup nobody has
ever restored is a guess, not a backup.

## What is NOT backed up — read this

**Only the database.** These are not in any backup:

- `back-end/storage/app/private/incidents` — uploaded incident evidence files
- `back-end/storage/app/private/reports`
- `back-end/storage/app/public/avatars`
- `back-end/.env`, which holds `APP_KEY`. Lose it and anything encrypted with it
  is unrecoverable even from a perfect database restore.

Restore onto a fresh machine and you get incident records whose attachments are
broken links. Copy `back-end/storage/app` and your `APP_KEY` somewhere safe
separately.

**Backups are on the same machine as the database.** `storage/app/backups` is
writable by the same account that runs the app, so ransomware encrypts the
backups along with the data — finding and destroying backups first is exactly
how ransomware makes money. Set the storage path to a synced cloud folder, or
copy snapshots off the machine on a schedule. A backup on the same machine as
the thing it protects is not really a backup.

These dumps are plaintext customer, supplier, and pricing data. A stolen backup
is itself a data breach; encrypt anything that leaves the building.

**This covers the local install only.** Production on Render uses PostgreSQL on
Aiven, and `BackupService` is MySQL-only — it hardcodes the `mysql` connection
and emits MySQL-specific SQL. The Backup page cannot back up production. Use
Aiven's managed snapshots for that.
```

- [ ] **Step 5: Fix the stale line in DEPLOYMENT.md**

In `DEPLOYMENT.md`, the scheduled-jobs section claims `schedule:list` prints four jobs and does not mention `backup:run` or `ledger:drift-check`. Replace the sentence

```
`php artisan schedule:list`: it must print **four** jobs.
```

with

```
`php artisan schedule:list`: it must print **six** jobs.
```

and add these two rows to the job table immediately after the `analytics:restock` row:

```
| `backup:run` | every minute, gated | Database backup, per Admin → Backup |
| `ledger:drift-check` | daily 02:30 | Proves the ledger still agrees with its sources |
```

Then replace the raw command block under "To run them" with a pointer to the installer:

```
To run them, one Windows Task Scheduler entry every minute is enough — Laravel
decides what is actually due. Register it from an elevated PowerShell:

```
powershell -ExecutionPolicy Bypass -File scripts\install-scheduler-task.ps1
```

See `scripts/README-backup.md` for verification and troubleshooting.
```

- [ ] **Step 6: Confirm nothing still references the deleted script**

```bash
cd /c/xampp/htdocs/Airking_Inventory
grep -rn "backup-db.ps1\|AirkingInventory-NightlyBackup\|AirkingBackups" --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git --exclude-dir=build .
```

Expected: hits only inside `scripts/README-backup.md` (historical context) and `back-end/config/backup.php`. Fix the `config/backup.php` comment, which still says "see scripts/backup-db.ps1, which hardcodes the same path" — replace that sentence with:

```php
    | Used by App\Services\BackupService to shell out to mysqldump (backup)
    | and mysql (restore). Leave unset to use the XAMPP default this machine
    | already has. Override in .env if MySQL lives somewhere else.
```

- [ ] **Step 7: Run the full backup test suite**

```bash
cd back-end
php artisan test --filter=Backup
```

Expected: PASS, 39 tests across five files.

- [ ] **Step 8: Commit**

```bash
git add -A scripts DEPLOYMENT.md back-end/config/backup.php
git commit -m "chore(backup): retire the standalone backup script for the in-app feature"
```

---

## Deliberately out of scope

State these plainly rather than half-doing them:

- **Off-machine mirror.** The old script copied to OneDrive. The in-app equivalent is to point the storage path at a synced folder, which the admin can already do — but it is a manual decision, documented in the README rather than automated.
- **Compression.** The script zipped. The service does not, so snapshots use more disk. Retention now bounds the total, which was the urgent part.
- **Backing up `storage/app`.** The incident and report files remain unprotected by anything. This needs its own plan — it is a different artifact with different size and retention characteristics.
- **Postgres support.** Production cannot use this feature at all. Separate, larger piece of work: branch on driver, shell to `pg_dump`/`pg_restore`, add a Postgres dialect to the PDO fallback, and add `postgresql-client` to the Render image.

---

## Self-review notes

Checked against the discussion that produced this plan:

- Catch-up gate → Task 1. ✓
- Retention → Tasks 2 (settings), 3 (engine), 4 (wiring), 5 (UI). ✓
- Timezone trap → Task 5 Step 4, README. ✓
- Single admin-configurable feature → Tasks 5, 8. ✓
- Popup removed → Task 9 Step 2, and the replacement task is `-Hidden` in Task 6. ✓
- MySQL-service prerequisite → Task 7, ordered before the old script is deleted. ✓
- Five other dead jobs revived → Task 6, documented in Task 9. ✓
- SHA-256 per snapshot → Task 8, with the overclaim avoided in the README. ✓
- Truncation check → Task 8 Steps 8-9; both dump paths now write a trailer, and `runPhpDump()` gained one because it had none. ✓

Type consistency check across tasks: `pruneOldBackups(array $policy): int` (Task 3) is what `pruneUsingStoredPolicy()` (Task 3) and the command (Task 4) call; `dumpLooksComplete(string): bool`, `checksumFor(string): string`, and `verifyChecksum(BackupLog): ?string` (Task 8) are used with those exact signatures in Steps 9, 10, and the tests. The schedule keys `keep_daily_days` / `keep_weekly_weeks` / `keep_monthly_months` are spelled identically in the controller (Task 2), the service (Task 3), and the React panel (Task 5). The new status string is `pruned` everywhere, and the new column is `checksum_sha256` everywhere.

Ordering constraint worth re-stating: **Task 9 must come last.** Tasks 6 and 7 build the replacement and prove it works unattended; only then is deleting the old script safe. Running Task 9 early leaves the install with zero automatic backups.
