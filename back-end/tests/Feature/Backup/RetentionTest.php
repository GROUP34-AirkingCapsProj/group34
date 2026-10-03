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

    public function test_a_corrupt_settings_blob_prunes_with_defaults_rather_than_throwing(): void
    {
        Carbon::setTestNow('2026-10-03 01:00:00');

        // getValue() returns the raw string when the blob does not decode as JSON,
        // and a bare scalar when it decodes to one -- neither is an array.
        \App\Models\SystemSetting::setValue(\App\Models\SystemSetting::KEY_BACKUP_SCHEDULE, 'abc');

        $recent = $this->snapshot('2026-09-23');   // 10 days old, inside the 14-day default
        $old    = $this->snapshot('2026-09-15');   // 18 days old, Tuesday, not the 1st
        $oldPath = $old->file_path;

        $deleted = app(BackupService::class)->pruneUsingStoredPolicy();

        $this->assertSame(1, $deleted);
        $this->assertFileExists($recent->file_path, 'the 14-day default must still protect this');
        $this->assertFileDoesNotExist($oldPath);
    }
}
