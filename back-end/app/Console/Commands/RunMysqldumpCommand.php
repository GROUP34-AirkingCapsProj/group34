<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Runs mysqldump in a real CLI SAPI. Windows + `php artisan serve` (cli-server)
 * often cannot create TCP sockets from child mysqldump processes (error 2004/10106);
 * spawning this command from the web request avoids that.
 */
class RunMysqldumpCommand extends Command
{
    protected $signature = 'backup:mysqldump {path : Absolute path for the .sql output file}';

    protected $description = 'Write a database dump to the given file (CLI helper for BackupService)';

    public function handle(BackupService $service): int
    {
        try {
            $service->runMysqldumpDirect($this->argument('path'));
            $this->info('Dump written.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
