<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eight reasons collapse to six; the nuance moves into the cause fields added
 * by 2026_08_11_000002.
 *
 *   theft, not_found          -> missing   (+ suspected_cause)
 *   damaged, delivery_damage  -> defective (+ root_cause)
 *   customer_return           -> return    (+ return_direction)
 *
 * Only 3 incident rows exist, all test data, so this is a small update rather
 * than a batched backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('incident_reports')->where('reason', 'theft')
            ->update(['reason' => 'missing', 'suspected_cause' => 'theft']);

        DB::table('incident_reports')->where('reason', 'not_found')
            ->update(['reason' => 'missing', 'suspected_cause' => 'unknown']);

        DB::table('incident_reports')->where('reason', 'damaged')
            ->update(['reason' => 'defective', 'root_cause' => 'internal']);

        DB::table('incident_reports')->where('reason', 'delivery_damage')
            ->update(['reason' => 'defective', 'root_cause' => 'delivery']);

        DB::table('incident_reports')->where('reason', 'customer_return')
            ->update(['reason' => 'return', 'return_direction' => 'from_customer']);
    }

    public function down(): void
    {
        DB::table('incident_reports')->where('reason', 'missing')
            ->update(['reason' => DB::raw("CASE WHEN suspected_cause = 'theft' THEN 'theft' ELSE 'not_found' END")]);

        DB::table('incident_reports')->where('reason', 'defective')
            ->update(['reason' => DB::raw("CASE WHEN root_cause = 'delivery' THEN 'delivery_damage' ELSE 'damaged' END")]);

        DB::table('incident_reports')->where('reason', 'return')->update(['reason' => 'customer_return']);
    }
};
