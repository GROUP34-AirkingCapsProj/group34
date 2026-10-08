<?php

namespace App\Console\Commands;

use App\Services\ReportRetentionService;
use Illuminate\Console\Command;

/**
 * Manual / scheduler-ready trigger for report file retention.
 *
 * No scheduler is configured for this XAMPP deploy, so in practice the
 * runs-list endpoint (Task 14) is what actually enforces the 90-day window
 * by calling ReportRetentionService opportunistically. This command exists
 * so an admin can prune by hand, and so a scheduler can be wired to it later
 * without any change to the pruning logic itself.
 */
class PruneReportRuns extends Command
{
    protected $signature = 'reports:prune';

    protected $description = 'Delete expired report files (90-day retention); the audit rows are kept.';

    public function handle(ReportRetentionService $retentionService): int
    {
        $prunedCount = $retentionService->prune();

        $this->info("Pruned {$prunedCount} expired report file(s). Report run rows were kept.");

        return Command::SUCCESS;
    }
}
