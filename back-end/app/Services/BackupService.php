<?php

namespace App\Services;

use App\Exceptions\BackupInProgressException;
use App\Models\BackupLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Runs and records database backups/restores via mysqldump/mysql.
 *
 * Every public entry point goes through the same named cache lock, so a
 * backup and a restore (or two of either) can never run concurrently against
 * the same database — the UI calls this "Conflict protection".
 */
class BackupService
{
    private const LOCK_NAME = 'backup:job';
    private const LOCK_TTL_SECONDS = 300;
    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Canonical 24-hour HH:MM pattern for a stored schedule time.
     *
     * \A..\z, not ^..$: PCRE's $ also matches before a trailing newline, so a
     * $-anchored version accepts "00:30\n". Public because BackupController
     * validates the same value on the way in — one pattern, one place.
     */
    public const TIME_REGEX = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    private const MYSQLDUMP_FLAGS = [
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--default-character-set=utf8mb4',
    ];

    /**
     * Left out of every dump, so a restore (which replays a dump with
     * `mysql < file`, fully replacing any table the dump mentions) cannot
     * revert this app's own live operational state back to whenever the
     * snapshot was taken:
     *
     * - backup_logs: reverting it would erase the audit trail this feature
     *   promises ("every restore attempt is logged") on every single
     *   restore — confirmed by testing: without this exclusion, a restore
     *   from an early snapshot wiped every row this table had gained since.
     * - cache_locks: this table holds the very lock (see withLock() below)
     *   that a backup or restore runs under. A dump taken mid-lock captures
     *   that lock row; restoring it mid-operation resurrects a stale lock
     *   with an owner token that no longer matches the one actually holding
     *   it, so release() can never find it again — confirmed by testing:
     *   this exact sequence left every later backup/restore permanently
     *   rejected with "already in progress" until the row was force-cleared.
     * - cache: CACHE_STORE=database — reverting arbitrary cached key/value
     *   entries to an old snapshot serves no purpose and risks resurrecting
     *   stale cached data.
     * - sessions: SESSION_DRIVER=database — reverting this can invalidate
     *   currently logged-in users or resurrect old, expired sessions.
     */
    private const TABLES_EXCLUDED_FROM_DUMP = ['backup_logs', 'cache_locks', 'cache', 'sessions'];

    public function resolveStorageDir(): string
    {
        $schedule = SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []);
        $override = trim((string) ($schedule['storage_path'] ?? ''));

        $dir = $override !== '' ? $override : storage_path('app/backups');

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Backup storage folder does not exist and could not be created: {$dir}");
            }
        }

        if (! is_writable($dir)) {
            throw new \RuntimeException("Backup storage folder is not writable: {$dir}");
        }

        return $dir;
    }

    public function resolveBinary(string $name): string
    {
        $configured = config("backup.{$name}_path");
        if ($configured && file_exists($configured)) {
            return $configured;
        }

        // Fall back to bare command name on PATH — lets `Process` itself
        // surface a clear "not found" error rather than silently failing here.
        return $name;
    }

    /**
     * @throws BackupInProgressException
     */
    public function createBackup(?User $user, string $trigger = 'manual'): BackupLog
    {
        return $this->withLock(fn () => $this->performBackup($user, $trigger));
    }

    /**
     * Restores the live database from a stored snapshot.
     *
     * Takes a fresh "safety" backup immediately before overwriting anything,
     * so a restore always has its own undo path. If that safety backup fails,
     * the restore itself is aborted rather than risking a one-way trip.
     *
     * @throws BackupInProgressException
     */
    public function restore(BackupLog $snapshot, User $user): BackupLog
    {
        return $this->withLock(function () use ($snapshot, $user) {
            $start = microtime(true);

            if (! $snapshot->file_path || ! is_file($snapshot->file_path)) {
                return $this->recordFailure('restore', 'manual', $user, 'Backup file no longer exists on disk', $start, $snapshot->log_id);
            }

            // Already holding the lock, so this calls the lock-free core
            // directly rather than createBackup() (which would deadlock
            // trying to re-acquire the same non-reentrant lock).
            $safetyBackup = $this->performBackup($user, 'pre_restore');
            if ($safetyBackup->status !== 'success') {
                return $this->recordFailure(
                    'restore',
                    'manual',
                    $user,
                    'Aborted: could not take a safety backup before restoring — ' . $safetyBackup->error_message,
                    $start,
                    $snapshot->log_id,
                );
            }

            try {
                $this->runMysqlClientImport($snapshot->file_path);
            } catch (ProcessTimedOutException $e) {
                return $this->recordFailure('restore', 'manual', $user, 'mysql restore timed out: ' . $e->getMessage(), $start, $snapshot->log_id);
            } catch (\Throwable $e) {
                return $this->recordFailure('restore', 'manual', $user, $e->getMessage(), $start, $snapshot->log_id);
            }

            return BackupLog::create([
                'action'               => 'restore',
                'triggered_by'         => 'manual',
                'triggered_by_user_id' => $user->user_id,
                'status'               => 'success',
                'restored_from_log_id' => $snapshot->log_id,
                'duration_ms'          => (int) ((microtime(true) - $start) * 1000),
            ]);
        });
    }

    /**
     * Is a scheduled backup owed right now?
     *
     * Deliberately "at or after", not "exactly at": this runs off a per-minute
     * scheduler tick, and an exact H:i comparison loses the entire day if the
     * machine is asleep, rebooting, or powered off for that one minute. The
     * old standalone task leaned on Task Scheduler's StartWhenAvailable for the
     * same reason -- its log shows runs landing at 09:50 and 05:12 for a 00:30
     * schedule. `last_scheduled_run_date` is what keeps a late catch-up from
     * becoming a second backup on a day already covered.
     */
    public function dueForScheduledRun(array $schedule): bool
    {
        if (empty($schedule['enabled'])) {
            return false;
        }

        $time = $schedule['time'] ?? null;
        if (! is_string($time) || ! preg_match(self::TIME_REGEX, $time)) {
            return false;
        }

        $now = now();

        if (($schedule['last_scheduled_run_date'] ?? null) === $now->toDateString()) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $now->greaterThanOrEqualTo($now->copy()->setTime($hour, $minute));
    }

    /**
     * The actual mysqldump run + history row, without acquiring the lock —
     * callers that already hold it (restore()'s pre-restore snapshot) call
     * this directly; createBackup() is the lock-acquiring public entry point.
     */
    private function performBackup(?User $user, string $trigger): BackupLog
    {
        $start = microtime(true);
        $dir = $this->resolveStorageDir();

        $timestamp = now()->format('Y-m-d_His');
        $fileName = "backup_{$timestamp}_" . Str::random(6) . '.sql';
        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        try {
            $this->runMysqldumpToFile($filePath);
        } catch (ProcessTimedOutException $e) {
            return $this->recordFailure('backup', $trigger, $user, 'mysqldump timed out: ' . $e->getMessage(), $start);
        } catch (\Throwable $e) {
            @unlink($filePath);

            return $this->recordFailure('backup', $trigger, $user, $e->getMessage(), $start);
        }

        if (! is_file($filePath) || filesize($filePath) === 0) {
            return $this->recordFailure('backup', $trigger, $user, 'mysqldump produced no output file', $start);
        }

        $log = BackupLog::create([
            'action'               => 'backup',
            'triggered_by'         => $trigger,
            'triggered_by_user_id' => $user?->user_id,
            'status'               => 'success',
            'file_path'            => $filePath,
            'file_name'            => $fileName,
            'file_size_bytes'      => filesize($filePath),
            'duration_ms'          => (int) ((microtime(true) - $start) * 1000),
        ]);

        SystemSetting::setValue(SystemSetting::KEY_BACKUP_LAST_RUN, [
            'log_id' => $log->log_id,
            'ran_at' => now()->toIso8601String(),
        ]);

        return $log;
    }

    /**
     * Public so `php artisan backup:mysqldump` can run under a real CLI SAPI.
     */
    public function runMysqldumpDirect(string $filePath): void
    {
        $db = config('database.connections.mysql');
        $binary = $this->resolveBinary('mysqldump');

        $ignoredTables = array_map(
            fn ($table) => "--ignore-table={$db['database']}.{$table}",
            self::TABLES_EXCLUDED_FROM_DUMP,
        );

        $args = array_merge([
            $binary,
            '-h', $db['host'],
            '-P', (string) $db['port'],
            '-u', $db['username'],
            '--protocol=TCP',
        ], self::MYSQLDUMP_FLAGS, $ignoredTables, [
            "--result-file={$filePath}",
            $db['database'],
        ]);

        $result = $this->runProcess($args, $this->mysqlEnv($db));

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'mysqldump failed');
        }

        if (! is_file($filePath) || filesize($filePath) === 0) {
            throw new \RuntimeException('mysqldump produced no output file');
        }
    }

    public function runMysqlRestoreDirect(string $filePath): void
    {
        $db = config('database.connections.mysql');
        $binary = $this->resolveBinary('mysql');
        $sql = file_get_contents($filePath);
        if ($sql === false) {
            throw new \RuntimeException('Could not read SQL dump file');
        }

        $args = [
            $binary,
            '-h', $db['host'],
            '-P', (string) $db['port'],
            '-u', $db['username'],
            '--protocol=TCP',
            $db['database'],
        ];

        $result = $this->runProcess($args, $this->mysqlEnv($db), $sql);

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'mysql restore failed');
        }
    }

    private function runMysqldumpToFile(string $filePath): void
    {
        // Windows web / `php artisan serve` cannot reliably spawn mysqldump
        // (TCP socket 10106) or even Symfony Process temp files (C:\WINDOWS
        // permission denied). Dump through the already-open Laravel PDO instead.
        if ($this->shouldUsePhpMysqlClient()) {
            $this->runPhpDump($filePath);

            return;
        }

        try {
            $this->runMysqldumpDirect($filePath);
        } catch (\Throwable $e) {
            // Last-resort fallback if the CLI binary path is broken.
            $this->runPhpDump($filePath);
        }
    }

    private function runMysqlClientImport(string $filePath): void
    {
        if ($this->shouldUsePhpMysqlClient()) {
            $this->runPhpRestore($filePath);

            return;
        }

        try {
            $this->runMysqlRestoreDirect($filePath);
        } catch (\Throwable $e) {
            $this->runPhpRestore($filePath);
        }
    }

    /** Prefer in-process PDO on Windows non-CLI SAPIs (artisan serve, Apache CGI, etc.). */
    private function shouldUsePhpMysqlClient(): bool
    {
        return PHP_OS_FAMILY === 'Windows' && PHP_SAPI !== 'cli';
    }

    private function runPhpDump(string $filePath): void
    {
        $database = (string) config('database.connections.mysql.database');
        $pdo = DB::connection()->getPdo();
        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot write backup file: {$filePath}");
        }

        try {
            fwrite($handle, "-- AirKing inventory backup (PHP)\n");
            fwrite($handle, '-- Database: '.$database."\n");
            fwrite($handle, '-- Generated: '.now()->toDateTimeString()."\n\n");
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = DB::select('SHOW TABLES');

            foreach ($tables as $row) {
                $table = (string) array_values((array) $row)[0];
                if ($table === '' || in_array($table, self::TABLES_EXCLUDED_FROM_DUMP, true)) {
                    continue;
                }

                $create = DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
                $createSql = $create->{'Create Table'} ?? null;
                if (! $createSql) {
                    continue;
                }

                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createSql.";\n\n");

                $offset = 0;
                $limit = 200;
                while (true) {
                    $chunk = DB::table($table)->offset($offset)->limit($limit)->get();
                    if ($chunk->isEmpty()) {
                        break;
                    }

                    $columns = array_keys((array) $chunk->first());
                    $colList = implode(',', array_map(fn ($c) => '`'.$c.'`', $columns));
                    $valueGroups = [];
                    foreach ($chunk as $record) {
                        $vals = [];
                        foreach ($columns as $col) {
                            $value = $record->{$col} ?? null;
                            if ($value === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = $pdo->quote((string) $value);
                            }
                        }
                        $valueGroups[] = '('.implode(',', $vals).')';
                    }
                    fwrite($handle, "INSERT INTO `{$table}` ({$colList}) VALUES\n".implode(",\n", $valueGroups).";\n\n");

                    if ($chunk->count() < $limit) {
                        break;
                    }
                    $offset += $limit;
                }
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        if (! is_file($filePath) || filesize($filePath) === 0) {
            throw new \RuntimeException('PHP dump produced no output file');
        }
    }

    private function runPhpRestore(string $filePath): void
    {
        $sql = file_get_contents($filePath);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('Could not read SQL dump file');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->splitSqlStatements($sql) as $statement) {
                DB::unprepared($statement);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if ($ch === "'" && ! $inDouble && ! $inBacktick && $prev !== '\\') {
                $inSingle = ! $inSingle;
            } elseif ($ch === '"' && ! $inSingle && ! $inBacktick && $prev !== '\\') {
                $inDouble = ! $inDouble;
            } elseif ($ch === '`' && ! $inSingle && ! $inDouble) {
                $inBacktick = ! $inBacktick;
            }

            if ($ch === ';' && ! $inSingle && ! $inDouble && ! $inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '' && ! str_starts_with($trimmed, '--')) {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function runProcess(array $args, array $env = [], ?string $input = null)
    {
        $tmp = $this->processTempDir();
        $env = array_merge([
            'TMP' => $tmp,
            'TEMP' => $tmp,
            'TMPDIR' => $tmp,
        ], $env);

        $pending = Process::path(base_path())->env($env)->timeout(300);
        if ($input !== null) {
            $pending = $pending->input($input);
        }

        return $pending->run($args);
    }

    private function processTempDir(): string
    {
        $dir = storage_path('framework/process-tmp');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function phpCliBinary(): string
    {
        $binary = PHP_BINARY;
        if (str_ends_with(strtolower($binary), 'php-cgi.exe')) {
            $candidate = dirname($binary).DIRECTORY_SEPARATOR.'php.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $binary;
    }

    private function mysqlEnv(array $db): array
    {
        $env = [];
        if (! empty($db['password'])) {
            $env['MYSQL_PWD'] = $db['password'];
        }

        return $env;
    }

    /**
     * @template T
     * @param  \Closure(): T  $callback
     * @return T
     * @throws BackupInProgressException
     */
    private function withLock(\Closure $callback)
    {
        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException $e) {
            throw new BackupInProgressException();
        }
    }

    private function recordFailure(
        string $action,
        string $trigger,
        ?User $user,
        string $message,
        float $start,
        ?int $restoredFromLogId = null,
    ): BackupLog {
        return BackupLog::create([
            'action'               => $action,
            'triggered_by'         => $trigger,
            'triggered_by_user_id' => $user?->user_id,
            'status'               => 'failed',
            'restored_from_log_id' => $restoredFromLogId,
            'error_message'        => $message,
            'duration_ms'          => (int) ((microtime(true) - $start) * 1000),
        ]);
    }
}
