<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BackupInProgressException;
use App\Http\Controllers\Controller;
use App\Models\BackupLog;
use App\Models\SystemSetting;
use App\Services\BackupService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BackupController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BackupService $service)
    {
    }

    // ── GET /api/backup/settings ──────────────────────────────
    public function settings()
    {
        $schedule = $this->scheduleWithDefaults();

        return $this->success([
            'schedule' => $schedule,
            'stats'    => $this->buildStats($schedule),
            'database' => [
                'name'         => (string) config('database.connections.mysql.database'),
                'table_count'  => $this->tableCount(),
                'storage_path' => $this->resolvedStoragePath($schedule),
            ],
        ], 'Backup settings retrieved');
    }

    // ── PUT /api/backup/settings ──────────────────────────────
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'enabled'             => 'sometimes|boolean',
            // See BackupService::TIME_REGEX for the pattern and why it's anchored
            // \A..\z. Array form, not a single pipe-delimited string: Laravel
            // explodes a string rule list on every "|", which would also split
            // the regex's own alternation inside ([01]\d|2[0-3]).
            'time'                => ['sometimes', 'string', 'regex:' . BackupService::TIME_REGEX],
            'frequency'           => ['sometimes', Rule::in(['daily'])],
            'storage_path'        => 'sometimes|nullable|string|max:500',
            'keep_daily_days'     => 'sometimes|integer|min:1|max:365',
            'keep_weekly_weeks'   => 'sometimes|integer|min:1|max:260',
            'keep_monthly_months' => 'sometimes|integer|min:1|max:120',
        ]);

        $current = $this->scheduleWithDefaults();
        SystemSetting::setValue(SystemSetting::KEY_BACKUP_SCHEDULE, array_merge($current, $validated));

        return $this->success($this->scheduleWithDefaults(), 'Backup schedule updated');
    }

    // ── POST /api/backup/run ──────────────────────────────────
    public function runNow(Request $request)
    {
        try {
            $log = $this->service->createBackup($request->user(), 'manual');
        } catch (BackupInProgressException $e) {
            return $this->error($e->getMessage(), 409);
        }

        if ($log->status !== 'success') {
            return $this->error($log->error_message ?? 'Backup failed', 500, ['log_id' => $log->log_id]);
        }

        return $this->success($this->transformLog($log), 'Backup completed successfully');
    }

    // ── GET /api/backup/history ───────────────────────────────
    public function history(Request $request)
    {
        $logs = BackupLog::with('triggeredByUser')
            ->orderByDesc('log_id')
            ->paginate($request->integer('per_page', 20));

        $logs->getCollection()->transform(fn ($log) => $this->transformLog($log));

        return $this->paginated($logs, 'Backup history retrieved');
    }

    // ── GET /api/backup/history/{id}/download ─────────────────
    public function download(Request $request, int $id)
    {
        $log = BackupLog::find($id);

        if (! $log || ! $log->file_path || ! is_file($log->file_path)) {
            return $this->notFound('Backup file not found');
        }

        return response()->download($log->file_path, $log->file_name ?? "backup_{$id}.sql");
    }

    // ── POST /api/backup/history/{id}/restore ─────────────────
    public function restore(Request $request, int $id)
    {
        $snapshot = BackupLog::find($id);

        if (! $snapshot || $snapshot->action !== 'backup' || $snapshot->status !== 'success') {
            return $this->notFound('Backup snapshot not found');
        }

        try {
            $log = $this->service->restore($snapshot, $request->user());
        } catch (BackupInProgressException $e) {
            return $this->error($e->getMessage(), 409);
        }

        if ($log->status !== 'success') {
            return $this->error($log->error_message ?? 'Restore failed', 500, ['log_id' => $log->log_id]);
        }

        return $this->success($this->transformLog($log), 'Database restored successfully');
    }

    // ── Helpers ────────────────────────────────────────────────

    private function scheduleWithDefaults(): array
    {
        $defaults = [
            'enabled'                 => false,
            'time'                    => '00:01',
            'frequency'               => 'daily',
            'storage_path'            => null,
            'last_scheduled_run_date' => null,
            'keep_daily_days'         => 14,
            'keep_weekly_weeks'       => 12,
            'keep_monthly_months'     => 12,
        ];

        $schedule = array_merge($defaults, SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []));

        // A stored blob predating this validation (or edited by hand) can hold a
        // non-numeric or out-of-range window. Fall back to the default rather than
        // reporting nonsense the Backup page would then display as fact.
        foreach (['keep_daily_days' => 365, 'keep_weekly_weeks' => 260, 'keep_monthly_months' => 120] as $key => $max) {
            $value = $schedule[$key];
            $schedule[$key] = (is_numeric($value) && (int) $value >= 1 && (int) $value <= $max)
                ? (int) $value
                : $defaults[$key];
        }

        return $schedule;
    }

    private function buildStats(array $schedule): array
    {
        $backupCounts = BackupLog::where('action', 'backup')
            ->selectRaw("status, count(*) as c")
            ->groupBy('status')
            ->pluck('c', 'status');

        $restoreCounts = BackupLog::where('action', 'restore')
            ->selectRaw("status, count(*) as c")
            ->groupBy('status')
            ->pluck('c', 'status');

        $lastRun = SystemSetting::getValue(SystemSetting::KEY_BACKUP_LAST_RUN, null);

        $overdue = (bool) $schedule['enabled']
            && $this->nextRunAt($schedule['time'], $schedule['last_scheduled_run_date'])?->lessThanOrEqualTo(now());

        return [
            'schedule_enabled'      => (bool) $schedule['enabled'],
            'next_scheduled_run'    => $schedule['enabled']
                ? $this->nextRunAt($schedule['time'], $schedule['last_scheduled_run_date'])?->toIso8601String()
                : null,
            'scheduled_run_overdue' => $overdue,
            'backups_total'         => (int) ($backupCounts->sum() ?? 0),
            'backups_failed'        => (int) ($backupCounts['failed'] ?? 0),
            'restores_total'        => (int) ($restoreCounts->sum() ?? 0),
            'restores_failed'       => (int) ($restoreCounts['failed'] ?? 0),
            'last_success_at'       => $lastRun['ran_at'] ?? null,
            'server_timezone'       => config('app.timezone'),
            'server_time'           => now()->toIso8601String(),
        ];
    }

    /**
     * When the next scheduled backup will actually happen.
     *
     * Must agree with BackupService::dueForScheduledRun(), which treats a backup as
     * due any time today once the configured time has passed and today has not run
     * yet. Reporting tomorrow in that window would have the dashboard promise
     * "tomorrow 00:30" while the next per-minute tick is about to fire a catch-up.
     */
    private function nextRunAt(?string $time, ?string $lastRunDate = null): ?Carbon
    {
        if (! is_string($time) || ! preg_match(BackupService::TIME_REGEX, $time)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        $now   = now();
        $today = $now->copy()->setTime($hour, $minute);

        if ($today->isFuture()) {
            return $today;
        }

        // Today's slot has passed. If today has not run yet, a catch-up is owed now.
        if ($lastRunDate !== $now->toDateString()) {
            return $now;
        }

        return $today->addDay();
    }

    private function resolvedStoragePath(array $schedule): string
    {
        $override = trim((string) ($schedule['storage_path'] ?? ''));

        return $override !== '' ? $override : storage_path('app/backups');
    }

    private function tableCount(): ?int
    {
        try {
            $db = config('database.connections.mysql.database');
            if (! $db) {
                return null;
            }
            $row = \Illuminate\Support\Facades\DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
                [$db]
            );

            return isset($row->c) ? (int) $row->c : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function transformLog(BackupLog $log): array
    {
        $user = $log->triggeredByUser;

        return [
            'log_id'           => $log->log_id,
            'action'           => $log->action,
            'trigger'          => $log->triggered_by,
            'status'           => $log->status,
            'file_name'        => $log->file_name,
            'file_size_bytes'  => $log->file_size_bytes,
            // Enough to eyeball against a recomputed hash; the full 64 chars are
            // in backup_logs for anyone who needs to compare properly.
            'checksum_short'   => $log->checksum_sha256 ? substr($log->checksum_sha256, 0, 12) : null,
            'restored_from_log_id' => $log->restored_from_log_id,
            'triggered_by_name' => $user
                ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                : ($log->triggered_by === 'scheduled' ? 'System' : null),
            'error_message'   => $log->error_message,
            'duration_ms'     => $log->duration_ms,
            'ran_at'          => $log->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
