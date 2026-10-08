<?php

namespace Tests\Feature\Backup;

use App\Models\BackupLog;
use App\Models\SystemSetting;
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

        // pruneOldBackups() scans the whole table, so each test must only be able
        // to see rows it created. DatabaseTransactions rolls this back.
        BackupLog::query()->delete();

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

    /**
     * Three fresh snapshots that soak up the ALWAYS_KEEP_NEWEST floor.
     *
     * Without these, a test holding a single aged snapshot proves nothing about
     * age at all: that snapshot is among the newest three, so the floor spares it
     * and the assertion passes for the wrong reason. Call this AFTER creating the
     * row under test, so the ballast holds the highest log_ids and the subject is
     * judged on its date. The ballast is days old, so every policy here keeps it
     * and it never perturbs a deleted-count.
     */
    private function ballast(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
            $this->snapshot($date);
        }
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
        $this->ballast();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($recent->file_path);
        $this->assertSame('success', $recent->refresh()->status);
    }

    public function test_an_ordinary_old_snapshot_is_pruned(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');      // 18 days old, a Tuesday, not the 1st
        $path = $old->file_path;
        $this->ballast();

        $pruned = app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertSame(1, $pruned);
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_pruned_row_survives_as_history(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');
        $this->ballast();

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
        $this->ballast();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($sunday->file_path);
    }

    public function test_a_first_of_month_snapshot_outlives_the_weekly_window(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $monthly = $this->snapshot('2026-03-01');  // 1st of month, ~7 months old
        $this->ballast();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($monthly->file_path);
    }

    public function test_a_sunday_beyond_the_weekly_window_is_pruned(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-05-03');      // a Sunday, ~22 weeks old, not the 1st
        $path = $old->file_path;
        $this->ballast();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileDoesNotExist($path);
    }

    public function test_a_corrupt_retention_value_falls_back_to_the_default_not_to_one_day(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        // 10 days old: inside the default 14-day window, outside a 1-day window.
        $recent = $this->snapshot('2026-09-23');
        $this->ballast();

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
        $this->ballast();

        app(BackupService::class)->pruneOldBackups(['keep_daily_days' => 99999]);

        $this->assertFileExists($recent->file_path);
    }

    public function test_failed_rows_are_left_alone(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        // 2026-01-02 is a Friday and not the 1st, with a real file on disk, so the
        // status filter is the ONLY thing that can spare this row.
        $failed = $this->snapshot('2026-01-02');
        $failed->forceFill(['status' => 'failed', 'error_message' => 'mysqldump exploded'])->save();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertSame('failed', $failed->refresh()->status);
        $this->assertFileExists($failed->refresh()->file_path);
    }

    public function test_restore_rows_are_never_pruned(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $restore = $this->snapshot('2026-01-05');
        $restore->forceFill(['action' => 'restore'])->save();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertFileExists($restore->refresh()->file_path);
    }

    public function test_a_corrupt_settings_blob_prunes_with_defaults_rather_than_throwing(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');

        // getValue() returns the raw string when the blob does not decode as JSON,
        // and a bare scalar when it decodes to one -- neither is an array.
        SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, 'abc');

        $recent = $this->snapshot('2026-09-23');   // 10 days old, inside the 14-day default
        $old    = $this->snapshot('2026-09-15');   // 18 days old, Tuesday, not the 1st
        $oldPath = $old->file_path;
        $this->ballast();

        $deleted = app(BackupService::class)->pruneUsingStoredPolicy();

        $this->assertSame(1, $deleted);
        $this->assertFileExists($recent->file_path, 'the 14-day default must still protect this');
        $this->assertFileDoesNotExist($oldPath);
    }

    public function test_the_newest_snapshots_are_never_pruned_however_aggressive_the_policy(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        // All far outside a 1-day window, none a Sunday or the 1st.
        $a = $this->snapshot('2026-06-02');
        $b = $this->snapshot('2026-06-03');
        $c = $this->snapshot('2026-06-04');
        $d = $this->snapshot('2026-06-05');

        $deleted = app(BackupService::class)->pruneOldBackups([
            'keep_daily_days'     => 1,
            'keep_weekly_weeks'   => 1,
            'keep_monthly_months' => 1,
        ]);

        $this->assertSame(1, $deleted, 'only the oldest of four may go');
        $this->assertFileDoesNotExist($a->file_path);
        $this->assertFileExists($b->file_path);
        $this->assertFileExists($c->file_path);
        $this->assertFileExists($d->file_path);
    }

    public function test_an_unreachable_storage_directory_leaves_rows_untouched(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');

        // Simulates an offline NAS or unplugged drive: the path is well-formed but
        // its directory does not resolve.
        $old->forceFill(['file_path' => $this->dir . DIRECTORY_SEPARATOR . 'gone-volume' . DIRECTORY_SEPARATOR . 'x.sql'])->save();
        $this->ballast();

        $deleted = app(BackupService::class)->pruneOldBackups($this->policy());

        $this->assertSame(0, $deleted);
        $this->assertSame('success', $old->refresh()->status, 'must not be reconciled away');
        $this->assertNotNull($old->refresh()->file_path);
    }

    public function test_a_genuinely_absent_file_is_reconciled_but_keeps_its_path(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');
        $path = $old->file_path;
        unlink($path);
        $this->ballast();

        app(BackupService::class)->pruneOldBackups($this->policy());

        $row = $old->refresh();
        $this->assertSame('pruned', $row->status);
        $this->assertSame($path, $row->file_path, 'the path stays so a human can go looking');
    }

    public function test_a_file_that_cannot_be_deleted_is_left_for_the_next_run(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');
        $old = $this->snapshot('2026-09-15');
        $this->ballast();

        $service = new class(...[]) extends BackupService {
            protected function deleteSnapshotFile(string $path): bool
            {
                return false;
            }
        };

        $deleted = $service->pruneOldBackups($this->policy());

        $this->assertSame(0, $deleted);
        $row = $old->refresh();
        $this->assertSame('success', $row->status, 'retried next run, not orphaned');
        $this->assertNotNull($row->file_path);
        $this->assertFileExists($row->file_path);
    }
}
