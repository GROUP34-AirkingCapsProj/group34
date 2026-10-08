<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/** CLI helper so restore also works when the web SAPI cannot spawn mysql.exe sockets. */
class RunMysqlRestoreCommand extends Command
{
    protected $signature = 'backup:mysql-restore {path : Absolute path of the .sql file to import}';

    protected $description = 'Import a SQL dump into the configured MySQL database';

    public function handle(BackupService $service): int
    {
        try {
            $service->runMysqlRestoreDirect($this->argument('path'));
            $this->info('Restore complete.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
