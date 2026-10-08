<?php

namespace App\Services;

use App\Exceptions\BackupInProgressException;
use App\Models\BackupLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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

    /**
     * The connection the app actually serves requests from -- `database.default`,
     * not a hardcoded 'mysql'. Every dump/restore/table-count call goes through
     * this rather than naming a driver, so the same code runs correctly against
     * the local MySQL/MariaDB database and the deployed PostgreSQL one.
     */
    private function activeConnectionName(): string
    {
        return (string) config('database.default');
    }

    /** @return array<string, mixed> */
    private function activeDbConfig(): array
    {
        return (array) config('database.connections.' . $this->activeConnectionName());
    }

    private function activeDriver(): string
    {
        return (string) ($this->activeDbConfig()['driver'] ?? '');
    }

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

            // The expected state the first time anything restores from a
            // backup taken before the most recent deploy: the row survived
            // (it is in the database), the local file did not (Render wipes
            // the container disk on every deploy), and the remote copy
            // syncToRemote() made is what makes this recoverable at all.
            $this->ensureLocalCopy($snapshot);

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

            // PostgreSQL only: see captureExcludedTableRows() for why this has
            // to happen around the restore rather than inside the dump itself.
            $preserved = $this->activeDriver() === 'pgsql' ? $this->captureExcludedTableRows() : [];

            try {
                $this->runRestoreFromFile($snapshot->file_path);

                if ($preserved !== []) {
                    $this->replayExcludedTableRows($preserved);
                }
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
     * Snapshots the current rows of every table in TABLES_EXCLUDED_FROM_DUMP,
     * immediately before a PostgreSQL restore begins.
     *
     * Exists because of a specific interaction, found by testing against a
     * real restore rather than assumed: pg_dump's `--clean` drops objects in
     * dependency order, but a table left out of the dump entirely
     * (`--exclude-table`) is invisible to that ordering. backup_logs has a
     * foreign key into users, so restoring failed trying to drop users' primary
     * key while backup_logs' now-unmanaged constraint still depended on it --
     * "cannot drop constraint users_pkey ... because other objects depend on
     * it". MySQL's path never hits this: mysqldump's restore runs under
     * `FOREIGN_KEY_CHECKS=0` for its whole duration, which sidesteps dependency
     * ordering entirely regardless of which tables are excluded.
     *
     * The fix is `--exclude-table-data` instead (see runPgDumpDirect): the
     * table's schema and constraints stay in the dump, so DROP ordering
     * resolves correctly, but it is recreated with none of its own rows. That
     * would silently empty backup_logs, sessions, cache and cache_locks on
     * every restore -- exactly the bug TABLES_EXCLUDED_FROM_DUMP exists to
     * prevent, confirmed by an earlier test: "a restore from an early snapshot
     * wiped every row this table had gained since". Capturing here and
     * replaying in replayExcludedTableRows() after the restore succeeds is
     * what keeps that guarantee true under the new exclude strategy.
     *
     * Deliberately not done with a database-level trick instead (disabling
     * triggers, or PostgreSQL's `session_replication_role = replica`): both
     * need a superuser-ish privilege Aiven's `avnadmin` role does not hold --
     * the same constraint `data:transfer` ran into and designed around
     * (DATA-TRANSFER.md). This works under an ordinary application role.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function captureExcludedTableRows(): array
    {
        $preserved = [];

        foreach (self::TABLES_EXCLUDED_FROM_DUMP as $table) {
            $preserved[$table] = DB::table($table)->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return $preserved;
    }

    /**
     * Replays rows captured by captureExcludedTableRows() after a successful
     * PostgreSQL restore, which -- because of `--exclude-table-data` -- leaves
     * these tables present but empty.
     *
     * Runs AFTER the restore, not inside the same transaction as the restore's
     * own `--single-transaction` psql process: that transaction belongs to a
     * separate subprocess and is already committed by the time control returns
     * here. A failure in this step is still reported as the restore failing
     * (restore()'s catch covers this call too) rather than succeeding with a
     * silent gap, because an empty backup_logs or a logged-out-everyone
     * sessions table is exactly the outcome TABLES_EXCLUDED_FROM_DUMP was
     * written to prevent.
     *
     * @param  array<string, list<array<string, mixed>>>  $preserved
     */
    private function replayExcludedTableRows(array $preserved): void
    {
        foreach ($preserved as $table => $rows) {
            if ($rows === []) {
                continue;
            }

            // Chunked, not one insert: an unbounded single statement for a
            // large audit_trail-sized table risks a packet/placeholder limit
            // the same way any other bulk insert would.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }

            // Explicit-value inserts never advance an identity column's
            // sequence -- confirmed earlier in the PostgreSQL migration work
            // (data:transfer, schema:export-pgsql hit the same thing). pg_dump
            // restores the sequence to wherever it stood when the SNAPSHOT was
            // taken, not where it stands now; this replay's rows have whatever
            // ids they happened to hold at THAT point, and the very next insert
            // into the table -- here, restore()'s own BackupLog::create() a few
            // lines later -- would collide with one of them without this.
            $this->resyncIdentitySequence($table);
        }
    }

    /**
     * `setval(pg_get_serial_sequence(...), max(id))` for one table's primary
     * key. No-ops (rather than erroring) for a table with no identity column
     * or no rows -- every table in TABLES_EXCLUDED_FROM_DUMP happens to have
     * one today, but this is defensive against that changing.
     */
    private function resyncIdentitySequence(string $table): void
    {
        $pk = DB::selectOne(
            "SELECT kcu.column_name
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_name = tc.constraint_name
                AND kcu.table_schema = tc.table_schema
              WHERE tc.table_schema = current_schema()
                AND tc.table_name = ?
                AND tc.constraint_type = 'PRIMARY KEY'",
            [$table],
        );

        if (! $pk) {
            return;
        }

        $column = $pk->column_name;

        DB::statement(
            "SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX(\"{$column}\") FROM \"{$table}\"), 1), true)",
            [$table, $column],
        );
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
            ->select(['log_id', 'file_path', 'remote_disk', 'remote_path', 'created_at', 'status', 'action'])
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

                        // Retention means retention everywhere: a policy of
                        // "keep 14 days" should not quietly mean "keep
                        // forever" the moment a remote copy exists. Best-effort
                        // and never blocks the local prune above, which is the
                        // part a human is actually waiting on disk space for.
                        $this->deleteRemoteCopy($log);

                        $log->forceFill([
                            'status'      => 'pruned',
                            'file_path'   => null,
                            'remote_disk' => null,
                            'remote_path' => null,
                        ])->save();

                        continue;
                    }

                    // The file is genuinely gone (the directory is reachable, the file is
                    // not). Reconcile the row but KEEP the path: `status` already marks it
                    // unusable, and the path is what a human needs to go looking.
                    $this->deleteRemoteCopy($log);
                    $log->forceFill(['status' => 'pruned', 'remote_disk' => null, 'remote_path' => null])->save();
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

        // mysqldump writes the first; runPhpDump() writes the second; pg_dump
        // writes the third -- its own real footer comment, not a marker this
        // app adds, so no equivalent of runPhpDump()'s synthetic trailer was
        // needed for Postgres.
        return str_contains($tail, 'Dump completed')
            || str_contains($tail, 'AirKing dump completed')
            || str_contains($tail, 'PostgreSQL database dump complete');
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
            $this->runDumpToFile($filePath);
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

        // Best-effort and after the fact: the local backup above is already a
        // complete, valid, checksummed success by this point. A remote sync
        // failure is real information -- the row stays remote_disk=null so the
        // panel shows it -- but it must never turn an already-successful
        // backup into a reported failure.
        $this->syncToRemote($log);

        return $log;
    }

    /**
     * Copies a just-created backup to the configured remote disk, if one is
     * configured. Exists because Render wipes the container disk on every
     * deploy (docs/DEPLOY-RENDER.md), so a local-only backup is real
     * protection only until the next one.
     *
     * Streamed, not `Storage::put($path, file_get_contents($path))`: a dump
     * can be tens of megabytes, and reading the whole thing into a PHP string
     * before handing it to Flysystem doubles the memory a large backup needs
     * for no benefit.
     */
    private function syncToRemote(BackupLog $log): void
    {
        $disk = trim((string) config('backup.remote_disk', ''));
        if ($disk === '' || ! $log->file_path) {
            return;
        }

        $remotePath = 'backups/' . $log->file_name;
        $stream = fopen($log->file_path, 'rb');

        if ($stream === false) {
            Log::warning('backup.remote_sync could not open local file', ['log_id' => $log->log_id]);

            return;
        }

        try {
            Storage::disk($disk)->writeStream($remotePath, $stream);
            $log->update(['remote_disk' => $disk, 'remote_path' => $remotePath]);
        } catch (\Throwable $e) {
            // Deliberately not recordFailure(): the backup this row describes
            // already succeeded. Losing offsite redundancy is worth logging,
            // not worth reporting as "your backup failed" when it did not.
            Log::warning('backup.remote_sync failed', [
                'log_id' => $log->log_id,
                'disk'   => $disk,
                'error'  => $e->getMessage(),
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Mirror of the local branch in performPrune(): best-effort, never blocks
     * the prune run it is called from. A failure here leaves an orphaned
     * object on the remote disk -- wasted space, not a correctness problem --
     * rather than leaving the local prune (what a human is actually waiting
     * on disk space for) stuck behind a network call to a disk that happens to
     * be unreachable.
     */
    private function deleteRemoteCopy(BackupLog $log): void
    {
        if (! $log->remote_disk || ! $log->remote_path) {
            return;
        }

        try {
            Storage::disk($log->remote_disk)->delete($log->remote_path);
        } catch (\Throwable $e) {
            Log::warning('backup.remote_sync delete failed', [
                'log_id' => $log->log_id,
                'disk'   => $log->remote_disk,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Downloads a backup's remote copy back to its recorded local path if the
     * local file is missing -- the expected state the first time anything
     * touches a backup row after a Render deploy, since the container disk
     * that held it is gone but backup_logs (database-backed) is not.
     *
     * A no-op, not an error, when there is nothing to recover from: no remote
     * copy recorded (remote sync was never configured, or failed at backup
     * time), or the local file is already there. Callers still get a normal
     * "file not found" failure from whatever they do next in that case --
     * this only fills the gap it can actually fill.
     */
    public function ensureLocalCopy(BackupLog $log): void
    {
        if ($log->file_path && is_file($log->file_path)) {
            return;
        }

        if (! $log->remote_disk || ! $log->remote_path) {
            return;
        }

        $disk = Storage::disk($log->remote_disk);
        if (! $disk->exists($log->remote_path)) {
            return;
        }

        $targetDir = dirname((string) $log->file_path);
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $remote = $disk->readStream($log->remote_path);
        if ($remote === null) {
            return;
        }

        $local = fopen($log->file_path, 'wb');
        if ($local === false) {
            fclose($remote);

            return;
        }

        try {
            stream_copy_to_stream($remote, $local);
        } finally {
            fclose($remote);
            fclose($local);
        }
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

    /**
     * PostgreSQL's dump/restore pair for a plain-SQL dump are two different
     * binaries, not one: pg_dump writes plain SQL with `--format=plain` (the
     * default), but pg_restore can only read the archive formats produced by
     * `-Fc`/`-Ft`/`-Fd` -- never plain SQL. A plain dump is restored the same
     * way any other .sql file is, by piping it into psql. This mirrors
     * mysqldump+mysql exactly: one binary writes SQL, the other replays it.
     *
     * Plain format (not custom) is deliberate, not a default left alone: it
     * keeps every assumption the rest of this file already makes about a
     * dump -- dumpLooksComplete()'s trailing-text check, download as a
     * human-readable/diffable .sql, "restore == replay this SQL" -- true for
     * both database engines, rather than forking the whole file around a
     * second binary format.
     *
     * --clean --if-exists makes pg_dump emit `DROP ... IF EXISTS` before each
     * CREATE, the same shape mysqldump produces by default (it adds
     * DROP TABLE IF EXISTS unless told not to) -- so a restore replaces
     * existing objects instead of erroring into them or silently appending to
     * them with duplicate-key failures partway through.
     */
    public function runPgDumpDirect(string $filePath): void
    {
        $db = $this->activeDbConfig();
        $binary = $this->resolveBinary('pg_dump');

        // --exclude-table-data, not --exclude-table: the latter drops the table
        // from the dump's object graph entirely, which sounds like the same
        // thing but is not. --clean orders its DROPs by dependency, and a table
        // excluded entirely is invisible to that ordering -- confirmed by
        // testing, this breaks exactly when an excluded table (backup_logs) has
        // a foreign key into a table the dump DOES manage (users): restoring
        // then fails trying to drop users_pkey while backup_logs' now-unmanaged
        // FK still depends on it. --exclude-table-data keeps the table's
        // schema and constraints in the dump -- so DROP ordering resolves
        // correctly -- while omitting its rows, which restore() separately
        // captures and replays around the restore (see captureExcludedTableRows)
        // so this exclusion still means what the constant's docblock says: a
        // restore cannot revert these tables' live rows.
        $excludeFlags = array_map(
            fn ($table) => "--exclude-table-data={$table}",
            self::TABLES_EXCLUDED_FROM_DUMP,
        );

        $args = array_merge([
            $binary,
            '-h', $db['host'],
            '-p', (string) $db['port'],
            '-U', $db['username'],
            '--no-password',
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
        ], $excludeFlags, [
            '-f', $filePath,
            $db['database'],
        ]);

        $result = $this->runProcess($args, $this->pgEnv($db));

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'pg_dump failed');
        }

        if (! is_file($filePath) || filesize($filePath) === 0) {
            throw new \RuntimeException('pg_dump produced no output file');
        }
    }

    /**
     * `-v ON_ERROR_STOP=1` is load-bearing, not a nicety: psql's default is to
     * print an error and KEEP GOING through the rest of the script, so without
     * it a restore that fails on statement 4 of 400 reports success and
     * silently leaves the database half old, half new. `--single-transaction`
     * makes that failure mode rollback instead: either the whole dump applies
     * or none of it does, matching what `mysql < dump.sql` already gives the
     * MySQL path for free (a single mysqldump connection round-trips as one
     * implicit unit there).
     *
     * `-f`, not stdin: a multi-megabyte dump piped through PHP's own memory
     * (as the mysql path does via file_get_contents()) is unnecessary when
     * psql can read the file itself.
     */
    public function runPsqlRestoreDirect(string $filePath): void
    {
        $db = $this->activeDbConfig();
        $binary = $this->resolveBinary('psql');

        $args = [
            $binary,
            '-h', $db['host'],
            '-p', (string) $db['port'],
            '-U', $db['username'],
            '-v', 'ON_ERROR_STOP=1',
            '--single-transaction',
            '-f', $filePath,
            $db['database'],
        ];

        $result = $this->runProcess($args, $this->pgEnv($db));

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'psql restore failed');
        }
    }

    /**
     * Dispatches by `database.default`'s driver. Renamed from
     * runMysqldumpToFile now that it also handles PostgreSQL -- the name was
     * only ever an implementation detail of the two private callers.
     */
    private function runDumpToFile(string $filePath): void
    {
        if ($this->activeDriver() === 'pgsql') {
            // No PHP-fallback branch here: the Windows/`php artisan serve`
            // problem the mysql fallback below exists for is specific to that
            // environment. The deployed PostgreSQL target always runs under a
            // real CLI SAPI in the Linux container, where pg_dump is simply a
            // subprocess like any other.
            $this->runPgDumpDirect($filePath);

            return;
        }

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

    /** Dispatches by `database.default`'s driver. Renamed from runMysqlClientImport. */
    private function runRestoreFromFile(string $filePath): void
    {
        if ($this->activeDriver() === 'pgsql') {
            $this->runPsqlRestoreDirect($filePath);

            return;
        }

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
     * PGPASSWORD is pg_dump/psql's equivalent of MYSQL_PWD -- env, not argv, so
     * the password never shows up in `ps` output or a process list on a
     * shared host. PGSSLMODE is forwarded too: Aiven (and most managed
     * Postgres) refuses a plaintext connection, and without this pg_dump
     * would default to 'prefer', which downgrades silently on a server that
     * actually requires TLS rather than failing loudly.
     */
    private function pgEnv(array $db): array
    {
        $env = [];
        if (! empty($db['password'])) {
            $env['PGPASSWORD'] = $db['password'];
        }
        if (! empty($db['sslmode'])) {
            $env['PGSSLMODE'] = $db['sslmode'];
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
