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
            'enabled'      => 'sometimes|boolean',
            'time'         => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'frequency'    => ['sometimes', Rule::in(['daily'])],
            'storage_path' => 'sometimes|nullable|string|max:500',
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
        return array_merge([
            'enabled'                 => false,
            'time'                    => '00:01',
            'frequency'               => 'daily',
            'storage_path'            => null,
            'last_scheduled_run_date' => null,
        ], SystemSetting::getValue(SystemSetting::KEY_BACKUP_SCHEDULE, []));
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

        return [
            'schedule_enabled'   => (bool) $schedule['enabled'],
            'next_scheduled_run' => $schedule['enabled'] ? $this->nextRunAt($schedule['time'])?->toIso8601String() : null,
            'backups_total'      => (int) ($backupCounts->sum() ?? 0),
            'backups_failed'     => (int) ($backupCounts['failed'] ?? 0),
            'restores_total'     => (int) ($restoreCounts->sum() ?? 0),
            'restores_failed'    => (int) ($restoreCounts['failed'] ?? 0),
            'last_success_at'    => $lastRun['ran_at'] ?? null,
            'server_timezone'    => config('app.timezone'),
            'server_time'        => now()->toIso8601String(),
        ];
    }

    private function nextRunAt(?string $time): ?Carbon
    {
        if (! $time) {
            return null;
        }

        $today = Carbon::createFromFormat('H:i', $time)->setDate(now()->year, now()->month, now()->day);

        return $today->isFuture() ? $today : $today->addDay();
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
