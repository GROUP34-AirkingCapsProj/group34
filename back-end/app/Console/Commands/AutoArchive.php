<?php

namespace App\Console\Commands;

use App\Models\ArchiveLog;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoArchive extends Command
{
    protected $signature = 'archive:auto
                            {--dry-run : Preview what would be archived without making changes}
                            {--module= : Run for a specific module only}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Auto-archive records based on configured rules in Archive Settings';

    private const MODULE_MAP = [
        'purchase_orders'   => [
            'model'      => \App\Models\PurchaseOrder::class,
            'date_col'   => 'updated_at',
            'status_col' => 'status_id',
        ],
        'purchase_requests' => [
            'model'      => \App\Models\PurchaseRequest::class,
            'date_col'   => 'updated_at',
            'status_col' => 'status_id',
        ],
        'transfers'         => [
            'model'      => \App\Models\Transfer::class,
            'date_col'   => 'updated_at',
            'status_col' => 'status_id',
        ],
        'adjustments'       => [
            'model'      => \App\Models\Adjustment::class,
            'date_col'   => 'updated_at',
            'status_col' => 'status_id',
        ],
        'issuances'         => [
            'model'      => \App\Models\Issuance::class,
            'date_col'   => 'updated_at',
            'status_col' => 'status_id',
        ],
    ];

    public function handle(): int
    {
        $isDryRun      = $this->option('dry-run');
        $moduleOnly    = $this->option('module');
        $startTime     = microtime(true);
        $summary       = [];
        $totalArchived = 0;

        $modules = $moduleOnly
            ? [$moduleOnly => self::MODULE_MAP[$moduleOnly] ?? null]
            : self::MODULE_MAP;

        if ($isDryRun) {
            $this->info('[DRY RUN] No changes will be made.');
        }

        foreach ($modules as $moduleKey => $config) {
            if (! $config) {
                $this->warn("Unknown module: {$moduleKey}");
                continue;
            }

            $settings = SystemSetting::getArchiveConfig($moduleKey);

            if (! $settings['auto_archive_enabled']) {
                $this->line("  Skipping {$moduleKey} — auto-archive disabled");
                $summary[$moduleKey] = ['archived' => 0, 'skipped' => 0, 'reason' => 'auto_archive_disabled'];
                continue;
            }

            if (! $settings['archive_after_days']) {
                $this->line("  Skipping {$moduleKey} — no age threshold configured");
                $summary[$moduleKey] = ['archived' => 0, 'skipped' => 0, 'reason' => 'no_age_threshold'];
                continue;
            }

            $cutoffDate  = Carbon::now()->subDays((int) $settings['archive_after_days']);
            $modelClass  = $config['model'];
            $dateCol     = $config['date_col'];
            $statusCol   = $config['status_col'];
            $archivable  = $settings['archivable_statuses'] ?? [];

            $query = $modelClass::query()->where($dateCol, '<', $cutoffDate);

            if (! empty($archivable)) {
                $query->whereIn($statusCol, $archivable);
            }

            $count = $query->count();

            if ($isDryRun) {
                $this->line("  [PREVIEW] {$moduleKey}: {$count} record(s) would be archived (cutoff: {$cutoffDate->toDateString()})");
                $summary[$moduleKey] = [
                    'would_archive' => $count,
                    'cutoff_date'   => $cutoffDate->toDateString(),
                    'statuses'      => $archivable,
                ];
                continue;
            }

            if ($count === 0) {
                $this->line("  {$moduleKey}: 0 records qualify");
                $summary[$moduleKey] = ['archived' => 0, 'skipped' => 0];
                continue;
            }

            $archived = 0;
            $query->chunkById(100, function ($records) use (&$archived, $moduleKey) {
                foreach ($records as $record) {
                    try {
                        $record->delete();
                        $archived++;
                    } catch (\Throwable $e) {
                        $this->warn("  Failed to archive {$moduleKey} #{$record->getKey()}: {$e->getMessage()}");
                    }
                }
            });

            $this->info("  {$moduleKey}: archived {$archived} record(s)");
            $summary[$moduleKey] = ['archived' => $archived, 'skipped' => 0];
            $totalArchived += $archived;
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $log = ArchiveLog::create([
            'triggered_by'         => 'scheduled',
            'triggered_by_user_id' => null,
            'run_type'             => $isDryRun ? 'preview' : 'auto_archive',
            'modules_processed'    => $summary,
            'total_archived'       => $totalArchived,
            'total_deleted'        => 0,
            'total_skipped'        => 0,
            'is_dry_run'           => $isDryRun,
            'duration_ms'          => $durationMs,
        ]);

        SystemSetting::setValue(SystemSetting::KEY_ARCHIVE_LAST_RUN, [
            'ran_at'         => now()->toIso8601String(),
            'total_archived' => $totalArchived,
            'is_dry_run'     => $isDryRun,
            'log_id'         => $log->log_id,
        ]);

        $this->info(
            $isDryRun
                ? "[DRY RUN COMPLETE] Would archive {$totalArchived} total records."
                : "Auto-archive complete. Archived {$totalArchived} records in {$durationMs}ms."
        );

        return Command::SUCCESS;
    }
}
