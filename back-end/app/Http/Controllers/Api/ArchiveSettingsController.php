<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArchiveLog;
use App\Models\SystemSetting;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ArchiveSettingsController extends Controller
{
    use ApiResponse;

    // ── GET /api/archive-settings ─────────────────────────────
    public function index()
    {
        return $this->success([
            'archive_config'        => SystemSetting::getValue(SystemSetting::KEY_ARCHIVE_CONFIG, []),
            'archive_schedule'      => SystemSetting::getValue(SystemSetting::KEY_ARCHIVE_SCHEDULE, []),
            'archive_notifications' => SystemSetting::getValue(SystemSetting::KEY_ARCHIVE_NOTIFICATIONS, []),
            'archive_last_run'      => SystemSetting::getValue(SystemSetting::KEY_ARCHIVE_LAST_RUN, null),
        ], 'Archive settings retrieved');
    }

    // ── PUT /api/archive-settings/module/{module} ─────────────
    public function updateModule(Request $request, string $module)
    {
        $validated = $request->validate([
            'auto_archive_enabled' => 'sometimes|boolean',
            'archive_after_days'   => 'sometimes|nullable|integer|min:1|max:3650',
            'retention_days'       => 'sometimes|nullable|integer|min:1|max:3650',
            'require_reason'       => 'sometimes|boolean',
            'pinnable'             => 'sometimes|boolean',
        ]);

        SystemSetting::setArchiveConfig($module, $validated);

        return $this->success(
            SystemSetting::getArchiveConfig($module),
            "Archive settings updated for {$module}"
        );
    }

    // ── PUT /api/archive-settings/schedule ───────────────────
    public function updateSchedule(Request $request)
    {
        $validated = $request->validate([
            'auto_archive_enabled'   => 'sometimes|boolean',
            'auto_archive_time'      => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'auto_archive_frequency' => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'retention_enabled'      => 'sometimes|boolean',
            'retention_frequency'    => ['sometimes', Rule::in(['daily', 'weekly', 'monthly'])],
            'retention_time'         => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
        ]);

        $current = SystemSetting::getValue(SystemSetting::KEY_ARCHIVE_SCHEDULE, []);
        SystemSetting::setValue(SystemSetting::KEY_ARCHIVE_SCHEDULE, array_merge($current, $validated));

        return $this->success(
            SystemSetting::getValue(SystemSetting::KEY_ARCHIVE_SCHEDULE),
            'Archive schedule updated'
        );
    }

    // ── POST /api/archive-settings/preview ───────────────────
    public function preview()
    {
        Artisan::call('archive:auto', ['--dry-run' => true]);
        $output = Artisan::output();

        $lastLog = ArchiveLog::where('is_dry_run', true)
            ->where('run_type', 'preview')
            ->latest('log_id')
            ->first();

        return $this->success([
            'preview_results' => $lastLog?->modules_processed ?? [],
            'console_output'  => $output,
            'ran_at'          => now()->toIso8601String(),
        ], 'Preview complete — no changes were made');
    }

    // ── POST /api/archive-settings/run-now ───────────────────
    public function runNow()
    {
        $userId = Auth::id();

        Artisan::call('archive:auto', ['--force' => true]);
        $output = Artisan::output();

        $lastLog = ArchiveLog::where('is_dry_run', false)
            ->where('run_type', 'auto_archive')
            ->latest('log_id')
            ->first();

        if ($lastLog) {
            $lastLog->update([
                'triggered_by'         => 'manual',
                'triggered_by_user_id' => $userId,
            ]);
        }

        return $this->success([
            'total_archived' => $lastLog?->total_archived ?? 0,
            'modules'        => $lastLog?->modules_processed ?? [],
            'console_output' => $output,
            'ran_at'         => now()->toIso8601String(),
        ], 'Auto-archive completed successfully');
    }

    // ── POST /api/archive-settings/run-cleanup ───────────────
    public function runCleanup()
    {
        Artisan::call('archive:cleanup', ['--force' => true]);
        $output = Artisan::output();

        $lastLog = ArchiveLog::where('run_type', 'retention_cleanup')
            ->latest('log_id')
            ->first();

        if ($lastLog) {
            $lastLog->update([
                'triggered_by'         => 'manual',
                'triggered_by_user_id' => Auth::id(),
            ]);
        }

        return $this->success([
            'total_deleted'  => $lastLog?->total_deleted ?? 0,
            'modules'        => $lastLog?->modules_processed ?? [],
            'console_output' => $output,
            'ran_at'         => now()->toIso8601String(),
        ], 'Retention cleanup completed');
    }

    // ── GET /api/archive-settings/logs ───────────────────────
    public function logs(Request $request)
    {
        $logs = ArchiveLog::with('triggeredByUser')
            ->orderBy('log_id', 'desc')
            ->paginate($request->integer('per_page', 20));

        $logs->getCollection()->transform(function ($log) {
            $user = $log->triggeredByUser;
            return [
                'log_id'              => $log->log_id,
                'run_type'            => $log->run_type,
                'triggered_by'        => $log->triggered_by,
                'triggered_by_name'   => $user
                    ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                    : ucfirst($log->triggered_by),
                'total_archived'      => $log->total_archived,
                'total_deleted'       => $log->total_deleted,
                'is_dry_run'          => $log->is_dry_run,
                'duration_ms'         => $log->duration_ms,
                'modules'             => $log->modules_processed,
                'ran_at'              => $log->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return $this->paginated($logs, 'Archive run logs retrieved');
    }
}
