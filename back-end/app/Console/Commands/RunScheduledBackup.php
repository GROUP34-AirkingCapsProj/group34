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
            // Silent unless asked. This command runs every minute so the Backup
            // page's arbitrary time can drive it, which means 1,439 of the day's
            // 1,440 runs end here. routes/console.php appends this command's
            // output to storage/logs/backup-run.log, so a line per skip is ~32 KB
            // a day -- 12 MB a year of recording that nothing happened. Liveness
            // is already answerable from the scheduler heartbeat; use -v when you
            // actually want to watch the gate decide.
            if ($this->output->isVerbose()) {
                $this->line('Not due — skipping.');
            }

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

            // Prune only after a success, so a run of failures can never age out
            // the last good snapshots and leave nothing restorable behind.
            //
            // NOT wrapped in a lock here. pruneOldBackups() takes the service's own
            // non-reentrant mutex internally, exactly as createBackup() just did and
            // released. Holding it around both would block for LOCK_WAIT_SECONDS and
            // then throw — the same deadlock restore() documents when it calls
            // performBackup() directly rather than createBackup().
            try {
                $pruned = $service->pruneUsingStoredPolicy();
                if ($pruned > 0) {
                    $this->line("Pruned {$pruned} aged snapshot(s).");
                }
            } catch (BackupInProgressException $e) {
                // A manual backup or restore holds the lock. Retention is not
                // urgent and the next tick retries, so this is a skip, not a
                // failure — the backup itself already succeeded.
                $this->line('Prune skipped: another backup operation is in progress.');
            } catch (\Throwable $e) {
                // A prune failure must not turn a good backup into a failed run.
                $this->warn('Prune failed: ' . $e->getMessage());
            }

            return self::SUCCESS;
        }

        $this->error("Backup failed: {$log->error_message}");

        return self::FAILURE;
    }
}
