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
