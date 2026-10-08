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

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL client binaries
    |--------------------------------------------------------------------------
    |
    | Used by App\Services\BackupService to shell out to pg_dump (backup) and
    | psql (restore) when `database.default` is the pgsql connection -- the
    | production path. Left unset by default: the production image installs
    | postgresql-client (see Dockerfile), which puts both on PATH, so the bare
    | command name already resolves there. Only set these if psql/pg_dump live
    | somewhere PATH does not reach.
    |
    */

    'pg_dump_path' => env('PG_DUMP_PATH'),
    'psql_path'     => env('PSQL_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Remote sync
    |--------------------------------------------------------------------------
    |
    | A local backup only protects you until the next deploy: Render replaces
    | the container disk on every deploy (same as it does for uploaded files --
    | see docs/DEPLOY-RENDER.md). Set this to a disk defined in
    | config/filesystems.php ('s3' works for both AWS S3 and anything
    | S3-compatible, including Cloudflare R2 via AWS_ENDPOINT) and
    | BackupService copies every successful backup there too, best-effort,
    | right after it finishes locally.
    |
    | Left empty (the default), nothing changes: every backup stays local-only,
    | exactly as before this existed.
    |
    */

    'remote_disk' => env('BACKUP_REMOTE_DISK'),

];
