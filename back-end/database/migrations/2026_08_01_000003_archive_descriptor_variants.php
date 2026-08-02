<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Archives the free-text descriptors the backfill lifted out of
     * existing products ("Ceiling Cassette Inverter", "Floor Standing
     * Inverter", …), leaving only the real variant vocabulary.
     *
     * Archived rather than deleted: ~200 products still store those strings
     * in appliance_details.variant, and the item forms surface a stored value
     * as "<value> (archived)" so nothing is silently blanked. Reversible by
     * restoring the rows.
     *
     * This matters more now that category scoping is gone — with no
     * category_id every variant appears in every dropdown.
     */
    public function up(): void
    {
        DB::table('variant_lookup')
            ->whereNotIn('variant_name', ['Inverter', 'Non-inverter'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    /**
     * Restores everything this archived. It cannot distinguish rows archived
     * by up() from rows an admin archived by hand afterwards, so a rollback
     * un-archives both.
     */
    public function down(): void
    {
        DB::table('variant_lookup')
            ->whereNotIn('variant_name', ['Inverter', 'Non-inverter'])
            ->update(['deleted_at' => null]);
    }
};
