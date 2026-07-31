<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * One-time backfill that populates capacity_lookup, variant_lookup and
 * issuable_unit_preset from values already in use across appliance_details,
 * product_sets and product_issuable_units.
 *
 * This is deliberately a plain class (not an Eloquent seeder invoked via
 * `db:seed`, and not inline in the migration) so it can be exercised directly
 * from a DatabaseTransactions test without the unreliability of running a
 * migration inside a transaction-wrapped test.
 *
 * Three rules drive every decision here — see the task write-up for the
 * full rationale:
 *   1. Case variants are preserved as separate rows (grouping happens in PHP,
 *      existence checks use BINARY) because products reference the exact
 *      string, and collapsing case would orphan whichever spelling loses.
 *   2. A category is only ever claimed when every product using a value
 *      agrees on the same category_id. Anything ambiguous gets category_id
 *      NULL, which means "applies everywhere" downstream.
 *   3. Every insert is guarded by an existence check first, so running this
 *      twice inserts nothing the second time.
 */
class LookupBackfill
{
    public static function run(): void
    {
        static::backfillCapacities();
        static::backfillVariants();
        static::backfillPresets();
    }

    // -----------------------------------------------------------------
    // Capacities
    // -----------------------------------------------------------------

    protected static function backfillCapacities(): void
    {
        $maxLen = 20;

        // Appliance-derived values carry a category when every product using
        // that exact string agrees on one.
        $rows = DB::table('appliance_details')
            ->join('products', 'products.product_id', '=', 'appliance_details.product_id')
            ->select('appliance_details.capacity_rating as value', 'products.category_id as category_id')
            ->get();

        $groups = static::groupByExactValue($rows, 'value', $maxLen);

        foreach ($groups as $value => $categoryIds) {
            $category = static::unambiguousCategory($categoryIds);
            static::insertIfMissing('capacity_lookup', 'capacity_value', $value, $category);
        }

        // product_sets carries no category, so every value found there is
        // global regardless of what appliance_details concluded.
        $setValues = DB::table('product_sets')
            ->select('capacity_rating as value')
            ->get();

        foreach (static::groupByExactValue($setValues, 'value', $maxLen) as $value => $ignored) {
            static::insertIfMissing('capacity_lookup', 'capacity_value', $value, null);
        }
    }

    // -----------------------------------------------------------------
    // Variants
    // -----------------------------------------------------------------

    protected static function backfillVariants(): void
    {
        $maxLen = 100;

        $rows = DB::table('appliance_details')
            ->join('products', 'products.product_id', '=', 'appliance_details.product_id')
            ->select('appliance_details.variant as value', 'products.category_id as category_id')
            ->get();

        $groups = static::groupByExactValue($rows, 'value', $maxLen);

        foreach ($groups as $value => $categoryIds) {
            $category = static::unambiguousCategory($categoryIds);
            static::insertIfMissing('variant_lookup', 'variant_name', $value, $category);
        }

        $setValues = DB::table('product_sets')
            ->select('variant as value')
            ->get();

        foreach (static::groupByExactValue($setValues, 'value', $maxLen) as $value => $ignored) {
            static::insertIfMissing('variant_lookup', 'variant_name', $value, null);
        }
    }

    // -----------------------------------------------------------------
    // Issuable unit presets
    // -----------------------------------------------------------------

    protected static function backfillPresets(): void
    {
        $rows = DB::table('product_issuable_units')
            ->select('label', 'unit_id', 'conversion_factor', 'is_whole_package')
            ->get();

        // Group by exact label (case-sensitive), keep the first occurrence's
        // attributes for each distinct label.
        $byLabel = [];
        foreach ($rows as $row) {
            $label = $row->label;

            if ($label === null) {
                continue;
            }

            if (trim($label) === '') {
                continue;
            }

            if (! array_key_exists($label, $byLabel)) {
                $byLabel[$label] = $row;
            }
        }

        foreach ($byLabel as $label => $row) {
            $exists = DB::table('issuable_unit_preset')
                ->whereRaw('BINARY label = ?', [$label])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('issuable_unit_preset')->insert([
                'label'                     => $label,
                'unit_id'                   => $row->unit_id,
                'default_conversion_factor' => $row->conversion_factor,
                'is_whole_package'          => $row->is_whole_package,
                'sort_order'                => 0,
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * Groups rows by the exact (case-sensitive) string in $column, skipping
     * null, empty, whitespace-only, and over-length values. Returns
     * [ exactValue => array of category_id values seen (or [] if the row
     * objects carry no category_id property) ].
     */
    protected static function groupByExactValue(iterable $rows, string $column, int $maxLen): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $value = $row->{$column} ?? null;

            if (! static::isValidValue($value, $maxLen)) {
                continue;
            }

            if (! array_key_exists($value, $groups)) {
                $groups[$value] = [];
            }

            if (property_exists($row, 'category_id')) {
                $groups[$value][] = $row->category_id;
            }
        }

        return $groups;
    }

    protected static function isValidValue(?string $value, int $maxLen): bool
    {
        if ($value === null) {
            return false;
        }

        if (trim($value) === '') {
            return false;
        }

        if (mb_strlen($value, 'UTF-8') > $maxLen) {
            return false;
        }

        return true;
    }

    /**
     * Returns the single category_id every entry agrees on, or null if the
     * value spans more than one category (or category tracking wasn't
     * available for this source).
     */
    protected static function unambiguousCategory(array $categoryIds): ?int
    {
        if (empty($categoryIds)) {
            return null;
        }

        $unique = array_unique($categoryIds);

        if (count($unique) !== 1) {
            return null;
        }

        $only = reset($unique);

        return $only === null ? null : (int) $only;
    }

    protected static function insertIfMissing(string $table, string $column, string $value, ?int $categoryId): void
    {
        $exists = DB::table($table)
            ->whereRaw("BINARY {$column} = ?", [$value])
            ->when(
                $categoryId === null,
                fn ($q) => $q->whereNull('category_id'),
                fn ($q) => $q->where('category_id', $categoryId)
            )
            ->exists();

        if ($exists) {
            return;
        }

        DB::table($table)->insert([
            $column       => $value,
            'category_id' => $categoryId,
            'sort_order'  => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
