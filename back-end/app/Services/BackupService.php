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

    /**
     * Newest successful snapshots that are never pruned, whatever the policy says.
     *
     * A floor, not a window: `keep_daily_days: 1` is a legal setting, and on a
     * machine that is often off for days every snapshot can age out of every tier
     * at once, leaving nothing restorable. Clock skew reaches the same place. The
     * newest few are the ones most likely to be wanted, so age never applies to
     * them.
     */
    private const ALWAYS_KEEP_NEWEST = 3;

    /**
     * How much of the file's tail to inspect for a completion trailer. Generous
     * enough for mysqldump's multi-line footer, small enough that the phrase
     * cannot be matched inside ordinary row data earlier in the dump.
     */
    private const TRAILER_WINDOW_BYTES = 512;

    /**
     * How many recent rows protectedSnapshotIds() will stat() looking for
     * ALWAYS_KEEP_NEWEST reachable ones. Bounded so a long tail of dead rows
     * cannot turn the floor into a full-table scan.
     */
    private const FLOOR_SCAN_WINDOW = 50;

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

            // Checked before the safety backup, not after: there is no reason to
            // spend a full dump on a restore that is already known to be unsafe.
            if ($problem = $this->verifyChecksum($snapshot)) {
                return $this->recordFailure('restore', 'manual', $user, $problem, $start, $snapshot->log_id);
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
     * Grandfather-father-son retention over the recorded snapshots.
     *
     * Deletes the FILE but keeps the history row, flipped to `pruned`. The row is
     * the audit trail this feature promises; deleting it would make an aged-out
     * backup indistinguishable from one that never happened.
     *
     * Driven off `backup_logs.created_at` rather than the file mtime, because a
     * file copied or restored from elsewhere carries a mtime that has nothing to
     * do with when the snapshot was taken.
     *
     * @param  array<string,mixed>  $policy
     * @return int  how many files this call actually deleted
     */
    public function pruneOldBackups(array $policy): int
    {
        return $this->withLock(fn () => $this->performPrune($policy));
    }

    /**
     * The prune itself, without the lock. Separate so the public entry point can
     * hold the same mutex every other destructive operation here holds, while a
     * caller that already owns it can reach the core directly.
     */
    private function performPrune(array $policy): int
    {
        $keepDailyDays     = $this->retentionWindow($policy, 'keep_daily_days', 14, 365);
        $keepWeeklyWeeks   = $this->retentionWindow($policy, 'keep_weekly_weeks', 12, 260);
        $keepMonthlyMonths = $this->retentionWindow($policy, 'keep_monthly_months', 12, 120);

        $deleted = 0;

        $protectedIds = $this->protectedSnapshotIds();

        BackupLog::query()
            ->select(['log_id', 'file_path', 'created_at', 'status', 'action'])
            ->where('action', 'backup')
            ->where('status', 'success')
            ->whereNotNull('file_path')
            ->orderBy('log_id')
            ->chunkById(100, function ($logs) use (&$deleted, $protectedIds, $keepDailyDays, $keepWeeklyWeeks, $keepMonthlyMonths) {
                foreach ($logs as $log) {
                    if (in_array($log->log_id, $protectedIds, true)) {
                        continue;
                    }

                    if ($this->shouldKeepSnapshot($log->created_at, $keepDailyDays, $keepWeeklyWeeks, $keepMonthlyMonths)) {
                        continue;
                    }

                    $path = (string) $log->file_path;

                    // is_file() is also false when the volume is unreachable -- an offline
                    // NAS, an unplugged drive, a denied stat. Treating that as "already
                    // gone" would erase the only pointer to a backup that still exists.
                    if (! is_dir(dirname($path))) {
                        continue;
                    }

                    if (is_file($path)) {
                        if (! $this->deleteSnapshotFile($path)) {
                            // Locked by a virus scanner or an open download. Leave the row
                            // untouched so the next run retries rather than losing track of
                            // a file that is still on disk.
                            \Illuminate\Support\Facades\Log::warning(
                                'Backup prune could not delete a snapshot; will retry next run',
                                ['log_id' => $log->log_id, 'path' => $path],
                            );

                            continue;
                        }

                        $deleted++;

                        $log->forceFill(['status' => 'pruned', 'file_path' => null])->save();

                        continue;
                    }

                    // The file is genuinely gone (the directory is reachable, the file is
                    // not). Reconcile the row but KEEP the path: `status` already marks it
                    // unusable, and the path is what a human needs to go looking.
                    $log->forceFill(['status' => 'pruned'])->save();
                }
            }, 'log_id', 'log_id');

        return $deleted;
    }

    /**
     * Isolated so a test can force a delete failure. Holding an open handle makes
     * unlink fail on Windows but not on Linux, so the branch is otherwise
     * untestable in a portable way.
     */
    protected function deleteSnapshotFile(string $path): bool
    {
        return @unlink($path);
    }

    /**
     * The newest snapshots the floor protects, counting only rows whose file is
     * actually on disk.
     *
     * A row whose file vanished outside the app -- deleted by hand, or recorded by
     * a different install that shared this database -- would otherwise occupy a
     * protected slot while being worthless, so the floor would guarantee three
     * rows rather than three restorable backups. This install hit exactly that:
     * five history rows pointed at another machine's checkout, one of them inside
     * the floor.
     *
     * Scans a bounded window rather than the whole table, so a long history of
     * dead rows cannot turn this into a full-table stat() sweep. If fewer than
     * ALWAYS_KEEP_NEWEST reachable rows exist in that window, fewer are protected
     * -- which is safe, because an unreachable file is also skipped by the
     * is_dir() guard in the prune loop and so cannot be deleted anyway.
     *
     * @return list<int>
     */
    private function protectedSnapshotIds(): array
    {
        $protected = [];

        BackupLog::query()
            ->select(['log_id', 'file_path'])
            ->where('action', 'backup')
            ->where('status', 'success')
            ->whereNotNull('file_path')
            ->orderByDesc('log_id')
            ->limit(self::FLOOR_SCAN_WINDOW)
            ->get()
            ->each(function ($log) use (&$protected) {
                if (count($protected) >= self::ALWAYS_KEEP_NEWEST) {
                    return false;
                }

                if (is_file((string) $log->file_path)) {
                    $protected[] = (int) $log->log_id;
                }

                return true;
            });

        return $protected;
    }

    /**
     * One retention window, read defensively.
     *
     * Falls back to the DEFAULT on anything invalid, deliberately not to a clamped
     * minimum. `max(1, (int) $v)` looks safe and is the opposite: `(int) "abc"` is
     * 0, so `max(1, 0)` is 1, turning a corrupted settings blob into "keep only one
     * day" -- the most destructive setting available. On the one path in this
     * service that deletes files, bad input has to fail toward keeping data.
     */
    private function retentionWindow(array $policy, string $key, int $default, int $max): int
    {
        $raw = $policy[$key] ?? null;

        if (! is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;

        return ($value >= 1 && $value <= $max) ? $value : $default;
    }

    /**
     * Keep if inside the daily window, or a Sunday inside the weekly window, or a
     * 1st-of-month inside the monthly window. Outside all three it is expendable.
     */
    private function shouldKeepSnapshot(
        ?\Illuminate\Support\Carbon $takenAt,
        int $keepDailyDays,
        int $keepWeeklyWeeks,
        int $keepMonthlyMonths,
    ): bool {
        if ($takenAt === null) {
            // No date to judge by -- never destroy it.
            return true;
        }

        if ($takenAt->greaterThanOrEqualTo(now()->copy()->subDays($keepDailyDays))) {
            return true;
        }

        if ($takenAt->isSunday() && $takenAt->greaterThanOrEqualTo(now()->copy()->subWeeks($keepWeeklyWeeks))) {
            return true;
        }

        if ($takenAt->day === 1 && $takenAt->greaterThanOrEqualTo(now()->copy()->subMonths($keepMonthlyMonths))) {
            return true;
        }

        return false;
    }

    /**
     * Reads the stored policy and prunes. Convenience for the scheduled command.
     *
     * SystemSetting::getValue() hands back the raw stored string when the blob
     * does not decode, and a bare scalar when it decodes to one (`5`, `"true"`),
     * so this cannot assume it got an array. A non-array means the settings row is
     * corrupt and nothing can be trusted about the admin's intent -- prune with an
     * empty policy, which retentionWindow() turns into the documented 14/12/12
     * defaults rather than anything more destructive.
     */
    public function pruneUsingStoredPolicy(): int
    {
        $policy = SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []);

        return $this->pruneOldBackups(is_array($policy) ? $policy : []);
    }

    /**
     * Did the writer finish?
     *
     * A dump killed partway through (full disk, power cut, killed process) is
     * indistinguishable from a good one by size alone — it parses, it looks like
     * SQL, and it restores as a half-empty database. Both of this service's dump
     * paths end with a trailer, and its absence is the signal.
     */
    public function dumpLooksComplete(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $window = (int) min($size, self::TRAILER_WINDOW_BYTES);
            fseek($handle, -$window, SEEK_END);
            $tail = (string) fread($handle, $window);
        } finally {
            fclose($handle);
        }

        // mysqldump writes the first; runPhpDump() writes the second.
        return str_contains($tail, 'Dump completed')
            || str_contains($tail, 'AirKing dump completed');
    }

    /** SHA-256 of the file on disk, lowercase hex. */
    public function checksumFor(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new \RuntimeException("Could not checksum backup file: {$path}");
        }

        return $hash;
    }

    /**
     * Confirms a stored snapshot is byte-for-byte what was written.
     *
     * @return string|null  null when it verifies (or predates checksums), an
     *                      operator-facing reason when it does not.
     */
    public function verifyChecksum(BackupLog $log): ?string
    {
        $recorded = $log->checksum_sha256;

        // Snapshots taken before the checksum column existed. Refusing these
        // would make upgrading destroy access to every backup already on disk.
        if (! $recorded) {
            return null;
        }

        if (! $log->file_path || ! is_file($log->file_path)) {
            return 'Backup file no longer exists on disk';
        }

        $actual = $this->checksumFor($log->file_path);

        // hash_equals is case-sensitive and hash_file() emits lowercase, but a
        // value that arrived from elsewhere may not be -- normalise before
        // comparing so the same bytes are not reported as tampered.
        if (! hash_equals(strtolower($recorded), strtolower($actual))) {
            return 'Integrity check failed: the file on disk no longer matches the '
                . 'checksum recorded when this backup was taken. It has been altered '
                . 'or corrupted since, and restoring from it is unsafe.';
        }

        return null;
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

        // A half-written dump restores as a half-empty database, so it is worse
        // than no backup at all: it looks like protection you do not have.
        if (! $this->dumpLooksComplete($filePath)) {
            @unlink($filePath);

            return $this->recordFailure(
                'backup',
                $trigger,
                $user,
                'Dump is incomplete (no completion trailer) — refusing to keep it. '
                . 'Usually a full disk or a killed process.',
                $start,
            );
        }

        $log = BackupLog::create([
            'action'               => 'backup',
            'triggered_by'         => $trigger,
            'triggered_by_user_id' => $user?->user_id,
            'status'               => 'success',
            'file_path'            => $filePath,
            'file_name'            => $fileName,
            'file_size_bytes'      => filesize($filePath),
            'checksum_sha256'      => $this->checksumFor($filePath),
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

    /**
     * Public seam over runPhpDump() so a test can prove the PDO dump path really
     * writes the trailer dumpLooksComplete() looks for. Asserting on a
     * hand-written fixture would pass even if this path emitted nothing.
     */
    public function runPhpDumpTo(string $filePath): void
    {
        $this->runPhpDump($filePath);
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
            // Last thing written, deliberately. dumpLooksComplete() treats the
            // absence of this line as proof the writer died partway through.
            // mysqldump has its own "Dump completed" trailer; this path had none,
            // so a truncated PDO dump was indistinguishable from a whole one.
            fwrite($handle, "-- AirKing dump completed\n");
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
