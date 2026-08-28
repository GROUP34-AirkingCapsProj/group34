<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repoints the ceiling-mounted units that 000003 typed as Split Type.
     *
     * THE ORDERING BUG THIS EXISTS TO CORRECT
     * ---------------------------------------
     * 000003 backfills ac_type_id, and 000004 adds the "Ceiling Mounted" type.
     * In filename order the backfill runs FIRST, so when it looked up its
     * variant patterns "Ceiling Mounted" was not in ac_type_lookup yet.
     * AcTypeBackfill skips a pattern whose type is missing:
     *
     *     if (! isset($typeIds[$typeName])) { continue; }
     *
     * so those rows fell through to the description pass, where every one of
     * them reads "...split-type air conditioner..." — truthfully, since a
     * ceiling-mounted unit IS a split system — and were typed Split Type.
     *
     * On the live catalogue that is 13 rows: 12 "Ceiling Mounted Inverter"
     * and 1 "Ceiling Mounted Non-Inverter", which is exactly the gap between
     * the 79 Split Type rows observed after migrating and the 66 expected.
     *
     * Re-running AcTypeBackfill does NOT fix them. It only writes where
     * ac_type_id IS NULL, which is what makes it safe to re-run — and these
     * rows are not null, they are confidently wrong. Hence a targeted update.
     *
     * WHY THIS IS NARROW
     * ------------------
     * It moves a row only when the variant names a ceiling-mounted unit AND
     * the row currently points at Split Type. A row somebody has since
     * corrected by hand to anything else is left alone, and a genuine Split
     * Type whose variant does not say "ceiling mounted" is untouched. That
     * makes this safe to re-run and safe to apply to a database where the
     * ordering bug never bit (it will match nothing).
     */
    public function up(): void
    {
        $ceilingMountedId = DB::table('ac_type_lookup')
            ->where('ac_type_name', 'Ceiling Mounted')
            ->value('ac_type_id');

        $splitTypeId = DB::table('ac_type_lookup')
            ->where('ac_type_name', 'Split Type')
            ->value('ac_type_id');

        // Nothing to do on a database that never seeded either type.
        if ($ceilingMountedId === null || $splitTypeId === null) {
            return;
        }

        DB::table('appliance_details')
            ->where('ac_type_id', $splitTypeId)
            ->whereRaw('LOWER(variant) LIKE ?', ['%ceiling mounted%'])
            ->update(['ac_type_id' => $ceilingMountedId]);
    }

    /**
     * Deliberately does nothing.
     *
     * Reversing this would mean retyping ceiling-mounted units back to Split
     * Type, which is the defect, not the prior state. The columns themselves
     * are dropped by 000002's down(), which is the honest way to undo the
     * whole feature.
     */
    public function down(): void
    {
    }
};
