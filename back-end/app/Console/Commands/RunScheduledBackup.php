<?php

namespace App\Console\Commands;

use App\Exceptions\BackupInProgressException;
use App\Models\SystemSetting;
use App\Services\BackupService;
use Illuminate\Console\Command;

class RunScheduledBackup extends Command
{
    protected $signature = 'backup:run {--force : Ignore the enabled/time gate and run immediately}';

    protected $description = 'Run a database backup if due, per the schedule saved in the Backup admin page';

    public function handle(BackupService $service): int
    {
        $schedule = SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []);

        if (! $this->option('force') && ! $service->dueForScheduledRun($schedule)) {
            $this->line('Not due — skipping.');

            return self::SUCCESS;
        }

        try {
            $log = $service->createBackup(null, 'scheduled');
        } catch (BackupInProgressException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        if ($log->status === 'success') {
            // Only mark today "done" on success — a transient failure (disk
            // full, mysqldump hiccup) should retry on the next minute's tick
            // rather than silently going quiet until tomorrow.
            $schedule = array_merge($schedule, ['last_scheduled_run_date' => now()->toDateString()]);
            SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, $schedule);

            $this->info("Backup complete: {$log->file_name}");

            return self::SUCCESS;
        }

        $this->error("Backup failed: {$log->error_message}");

        return self::FAILURE;
    }
}
