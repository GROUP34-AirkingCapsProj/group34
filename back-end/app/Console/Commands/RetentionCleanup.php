<?php

namespace App\Console\Commands;

use App\Models\ArchiveLog;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RetentionCleanup extends Command
{
    protected $signature = 'archive:cleanup
                            {--dry-run : Preview what would be permanently deleted}
                            {--module= : Run for a specific module only}';

    protected $description = 'Permanently delete archived records that have exceeded their retention period';

    private const MODULE_MAP = [
        'purchase_orders'   => \App\Models\PurchaseOrder::class,
        'purchase_requests' => \App\Models\PurchaseRequest::class,
        'transfers'         => \App\Models\Transfer::class,
        'adjustments'       => \App\Models\Adjustment::class,
        'issuances'         => \App\Models\Issuance::class,
        'products'          => \App\Models\Product::class,
        'customers'         => \App\Models\Customer::class,
        'suppliers'         => \App\Models\Supplier::class,
    ];

    public function handle(): int
    {
        $isDryRun     = $this->option('dry-run');
        $moduleOnly   = $this->option('module');
        $summary      = [];
        $totalDeleted = 0;

        $modules = $moduleOnly
            ? [$moduleOnly => self::MODULE_MAP[$moduleOnly] ?? null]
            : self::MODULE_MAP;

        if ($isDryRun) {
            $this->info('[DRY RUN] No permanent deletions will be made.');
        }

        foreach ($modules as $moduleKey => $modelClass) {
            if (! $modelClass) {
                $this->warn("Unknown module: {$moduleKey}");
                continue;
            }

            $settings = SystemSetting::getArchiveConfig($moduleKey);

            if (! $settings['retention_days']) {
                $this->line("  Skipping {$moduleKey} — no retention limit set (keep forever)");
                continue;
            }

            $retentionCutoff = Carbon::now()->subDays((int) $settings['retention_days']);

            $query = $modelClass::onlyTrashed()->where('deleted_at', '<', $retentionCutoff);
            $count = $query->count();

            if ($isDryRun) {
                $this->line("  [PREVIEW] {$moduleKey}: {$count} archived record(s) would be permanently deleted");
                $summary[$moduleKey] = ['would_delete' => $count];
                continue;
            }

            if ($count === 0) {
                $this->line("  {$moduleKey}: 0 records past retention period");
                continue;
            }

            $deleted = 0;
            $query->chunkById(100, function ($records) use (&$deleted) {
                foreach ($records as $record) {
                    try {
                        $record->forceDelete();
                        $deleted++;
                    } catch (\Throwable $e) {
                        $this->warn("  Failed to delete #{$record->getKey()}: {$e->getMessage()}");
                    }
                }
            });

            $this->info("  {$moduleKey}: permanently deleted {$deleted} record(s)");
            $summary[$moduleKey] = ['deleted' => $deleted];
            $totalDeleted += $deleted;
        }

        ArchiveLog::create([
            'triggered_by'      => 'scheduled',
            'run_type'          => $isDryRun ? 'preview' : 'retention_cleanup',
            'modules_processed' => $summary,
            'total_archived'    => 0,
            'total_deleted'     => $totalDeleted,
            'total_skipped'     => 0,
            'is_dry_run'        => $isDryRun,
        ]);

        $this->info(
            $isDryRun
                ? "[DRY RUN COMPLETE] Would permanently delete {$totalDeleted} records."
                : "Retention cleanup complete. Permanently deleted {$totalDeleted} records."
        );

        return Command::SUCCESS;
    }
}
