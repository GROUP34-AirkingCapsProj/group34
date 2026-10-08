<?php

namespace Tests\Feature\Backup;

use App\Exceptions\BackupInProgressException;
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

    /*
     * No custom tearDown(). The framework's own teardown
     * (InteractsWithTestCaseLifecycle::tearDownTheTestEnvironment) already runs the
     * DatabaseTransactions rollback FIRST, then Mockery::close(), then resets
     * Carbon. An override calling Mockery::close() before parent::tearDown() lets
     * an unmet-expectation throw escape before the rollback callback runs, which
     * strands the open transaction and leaves the system_settings row locked --
     * every later test in the file then dies of a lock wait timeout instead of its
     * own assertion.
     */

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

    /**
     * Silent on purpose. This runs every minute so the Backup page's arbitrary
     * time can drive it, and routes/console.php appends its output to
     * backup-run.log -- a line per skip would be ~12 MB a year of recording that
     * nothing happened.
     */
    public function test_a_run_that_is_not_due_does_nothing_and_says_nothing(): void
    {
        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('dueForScheduledRun')->once()->andReturn(false);
        $service->shouldNotReceive('createBackup');
        $service->shouldNotReceive('pruneUsingStoredPolicy');
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')
            ->doesntExpectOutputToContain('Not due')
            ->assertExitCode(0);
    }

    public function test_a_skipped_run_explains_itself_when_verbose(): void
    {
        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('dueForScheduledRun')->once()->andReturn(false);
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run', ['-v' => true])
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
            ->andThrow(new BackupInProgressException());
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Backup complete')
            ->expectsOutputToContain('Prune skipped')
            ->assertExitCode(0);
    }

    /**
     * Any other prune failure is also non-fatal — the backup succeeded, which is
     * the part that matters. It must be reported, not swallowed silently.
     */
    public function test_an_unexpected_prune_failure_is_warned_about_but_not_fatal(): void
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
            ->andThrow(new \RuntimeException('disk went away'));
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backup:run')
            ->expectsOutputToContain('Prune failed')
            ->assertExitCode(0);
    }
}
