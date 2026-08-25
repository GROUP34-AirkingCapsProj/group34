<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Populates appliance_details.ac_type_id and .unit_role for rows that predate
 * those columns, reading products.description where the aircon kind has lived
 * as prose since the catalogue was seeded.
 *
 * Idempotent: only ever writes to rows where the target column is still NULL,
 * so a second run is a no-op and a hand-corrected row is never clobbered.
 */
class AcTypeBackfill
{
    /**
     * Description substring => ac_type_lookup name.
     *
     * Ordered longest-phrase-first so "floor standing" cannot be matched by a
     * shorter pattern first. Matching is case-insensitive.
     */
    private const PATTERNS = [
        'floor standing' => 'Floor Standing',
        'floor-standing' => 'Floor Standing',
        'split-type'     => 'Split Type',
        'split type'     => 'Split Type',
        'window-type'    => 'Window Type',
        'window type'    => 'Window Type',
        'cassette'       => 'Cassette',
        'ducted'         => 'Ducted',
        'portable'       => 'Portable',
    ];

    public static function run(): void
    {
        $typeIds = DB::table('ac_type_lookup')
            ->pluck('ac_type_id', 'ac_type_name')
            ->all();

        foreach (self::PATTERNS as $needle => $typeName) {
            if (! isset($typeIds[$typeName])) {
                continue;
            }

            DB::table('appliance_details')
                ->join('products', 'products.product_id', '=', 'appliance_details.product_id')
                ->whereNull('appliance_details.ac_type_id')
                ->whereRaw('LOWER(products.description) LIKE ?', ['%' . $needle . '%'])
                ->update(['appliance_details.ac_type_id' => $typeIds[$typeName]]);
        }

        // Every pre-existing appliance row describes a whole product, because
        // until now there was no way to record half of one.
        DB::table('appliance_details')
            ->whereNull('unit_role')
            ->update(['unit_role' => 'complete']);
    }
}
