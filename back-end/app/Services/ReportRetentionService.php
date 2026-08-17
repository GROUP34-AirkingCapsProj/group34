<?php

namespace App\Services;

use App\Models\ReportRun;
use Illuminate\Support\Facades\Storage;

/**
 * Enforces the 90-day retention window on generated report FILES.
 *
 * The `report_runs` ROW is an audit record — who ran what, with which
 * filters, when — and is never deleted here. Only the underlying file is
 * removed once its `expires_at` has passed; the row is stamped with
 * `file_pruned_at` and its `file_path` cleared so the UI can still show the
 * run happened and offer an explicit "re-run" as a new run.
 *
 * This is the single implementation of the pruning logic, shared by:
 *  - the `reports:prune` artisan command (manual / scheduler-ready), and
 *  - an opportunistic call from the runs-list endpoint (Task 14), which is
 *    what actually enforces the window on this XAMPP deploy, since no
 *    scheduler is configured in bootstrap/app.php.
 */
class ReportRetentionService
{
    /** Must match the disk ReportRunner writes files to. */
    private const DISK = 'local';

    /**
     * Prune every expired, not-yet-pruned run's file.
     *
     * @return int number of files actually pruned
     */
    public function prune(): int
    {
        $prunedCount = 0;

        ReportRun::query()
            ->whereNull('file_pruned_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('report_run_id')
            ->chunkById(100, function ($runs) use (&$prunedCount) {
                foreach ($runs as $run) {
                    if ($run->file_path !== null && Storage::disk(self::DISK)->exists($run->file_path)) {
                        Storage::disk(self::DISK)->delete($run->file_path);
                    }

                    $run->update([
                        'file_pruned_at' => now(),
                        'file_path' => null,
                    ]);

                    $prunedCount++;
                }
            }, 'report_run_id', 'report_run_id');

        return $prunedCount;
    }
}
