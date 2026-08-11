<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Missing, Defective and Returned as inventory statuses.
 *
 * Category `inventory` is where `In Stock` (26) already lives and what
 * item_serial.status_id already points at, so a serial lookup can tell the
 * truth about a held unit without consulting stock_holds.
 *
 * Idempotent: updateOrInsert on the natural key, so re-running is safe.
 */
class StockHoldStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Missing', 'Defective', 'Returned'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'inventory', 'status_name' => $name],
                ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // An incident whose stock is held pending an inspection is NOT resolved
        // — nobody has decided anything yet. Reading "Resolved" on the triage
        // list while the units sit un-inspected in a corner overstates what
        // approval actually did.
        DB::table('status_lookup')->updateOrInsert(
            ['status_category' => 'incident', 'status_name' => 'Awaiting Inspection'],
            ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
