<?php

namespace App\Console\Commands;

use App\Models\WarrantyRecord;
use Illuminate\Console\Command;

/**
 * Bring the stored warranty status in line with the calendar.
 *
 * This is a TIDY-UP, not the source of truth. Screens derive expiry from
 * warranty_end_date (see WarrantyRecord::getEffectiveStatusIdAttribute), so a
 * system where this command has never run still shows every warranty
 * correctly. What it buys is a status_id that reporting and exports can trust
 * without re-deriving it, and it replaces the batch UPDATE that used to run
 * inside the list endpoint every time somebody opened the page.
 *
 * Claimed records are left alone: claimed is a stored fact that outlives the
 * cover it was claimed against.
 */
class ExpireWarranties extends Command
{
    protected $signature   = 'warranties:expire';
    protected $description = 'Mark active warranties whose end date has passed as expired';

    public function handle(): int
    {
        $expired = WarrantyRecord::where('status_id', WarrantyRecord::STATUS_ACTIVE)
            ->whereNotNull('warranty_end_date')
            ->whereDate('warranty_end_date', '<', now()->toDateString())
            ->update(['status_id' => WarrantyRecord::STATUS_EXPIRED]);

        $this->info($expired === 0
            ? 'No warranties needed expiring.'
            : "Expired {$expired} warranty record(s).");

        return self::SUCCESS;
    }
}
