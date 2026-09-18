<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MySQL client binaries
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\BackupService to shell out to mysqldump (backup)
    | and mysql (restore). Leave unset to use the XAMPP default this machine
    | already has (see scripts/backup-db.ps1, which hardcodes the same path).
    | Override in .env if MySQL lives somewhere else.
    |
    */

    'mysqldump_path' => env('MYSQLDUMP_PATH', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'),
    'mysql_path'      => env('MYSQL_PATH', 'C:\\xampp\\mysql\\bin\\mysql.exe'),

];
