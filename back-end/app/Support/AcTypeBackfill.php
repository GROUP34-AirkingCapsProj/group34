<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Populates appliance_details.ac_type_id and .unit_role for rows that predate
 * those columns, reading appliance_details.variant first and falling back to
 * products.description.
 *
 * WHY VARIANT BEATS DESCRIPTION
 *
 * Nearly every non-window aircon's description says "...split-type air
 * conditioner...", because a cassette, a floor-stander and a ceiling-mounted
 * unit all technically ARE split systems: indoor head plus outdoor condenser.
 * The description therefore names the refrigeration topology, not the form
 * factor. The form factor lives in variant ("Ceiling Cassette Inverter",
 * "Floor Standing Non-Inverter", "Ceiling Mounted Inverter").
 *
 * Reading the description alone mistyped 51 of the 140 live aircons as Split
 * Type — 15 cassettes, 23 floor-standers and 13 ceiling-mounted units — which
 * is precisely the failure this backfill's NULL-on-no-match rule exists to
 * avoid: a confident wrong answer nobody ever re-checks is worse than an
 * obviously empty field. Do not "simplify" the variant pass away.
 *
 * Idempotent: only ever writes to rows where the target column is still NULL,
 * so a second run is a no-op and a hand-corrected row is never clobbered. That
 * same NULL guard is what makes pass 1 win over pass 2 — the description pass
 * can only reach rows the variant pass declined to type.
 */
class AcTypeBackfill
{
    /**
     * appliance_details.variant substring => ac_type_lookup name.
     *
     * Ordered most-specific-first so a looser pattern cannot claim a row
     * first: "Slim Floor Standing Inverter" must land on Floor Standing, and
     * the abbreviated "Floor Inverter" spelling must reach it too. Matching is
     * case-insensitive.
     */
    private const VARIANT_PATTERNS = [
        'cassette'        => 'Cassette',
        'ceiling mounted' => 'Ceiling Mounted',
        'floor standing'  => 'Floor Standing',
        'floor-standing'  => 'Floor Standing',
        'floor inverter'  => 'Floor Standing',
        'window'          => 'Window Type',
        'portable'        => 'Portable',
        'ducted'          => 'Ducted',
    ];

    /**
     * products.description substring => ac_type_lookup name.
     *
     * The fallback for rows whose variant says nothing about form factor
     * (marketing names like "Titan Gold"). Ordered longest-phrase-first so
     * "floor standing" cannot be matched by a shorter pattern first.
     */
    private const DESCRIPTION_PATTERNS = [
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

        // Pass 1: the specific form factor, from variant.
        foreach (self::VARIANT_PATTERNS as $needle => $typeName) {
            if (! isset($typeIds[$typeName])) {
                continue;
            }

            DB::table('appliance_details')
                ->whereNull('ac_type_id')
                ->whereRaw('LOWER(variant) LIKE ?', ['%' . $needle . '%'])
                ->update(['ac_type_id' => $typeIds[$typeName]]);
        }

        // Pass 2: prose, for whatever pass 1 left NULL.
        foreach (self::DESCRIPTION_PATTERNS as $needle => $typeName) {
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
