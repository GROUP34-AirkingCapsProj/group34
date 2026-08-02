<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * One-time backfill that populates capacity_lookup and variant_lookup from
 * values already in use across appliance_details and product_sets.
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
 *   2. Every insert is guarded by an existence check first, so running this
 *      twice inserts nothing the second time.
 *   3. Both sources (appliance_details and product_sets) are reconciled into
 *      one set of distinct values *before* anything is written, so a value
 *      named by both sources produces exactly one row.
 */
class LookupBackfill
{
    public static function run(): void
    {
        static::backfillCapacities();
        static::backfillVariants();
    }

    // -----------------------------------------------------------------
    // Capacities
    // -----------------------------------------------------------------

    protected static function backfillCapacities(): void
    {
        $maxLen = 20;

        $applianceRows = DB::table('appliance_details')
            ->select('capacity_rating as value')
            ->get();

        $setRows = DB::table('product_sets')
            ->select('capacity_rating as value')
            ->get();

        static::backfillLookupTable($applianceRows, $setRows, 'capacity_lookup', 'capacity_value', $maxLen);
    }

    // -----------------------------------------------------------------
    // Variants
    // -----------------------------------------------------------------

    protected static function backfillVariants(): void
    {
        $maxLen = 100;

        $applianceRows = DB::table('appliance_details')
            ->select('variant as value')
            ->get();

        $setRows = DB::table('product_sets')
            ->select('variant as value')
            ->get();

        static::backfillLookupTable($applianceRows, $setRows, 'variant_lookup', 'variant_name', $maxLen);
    }

    /**
     * Reconciles the values from both sources into one distinct set *before*
     * writing anything, so a value named by appliance_details and product_sets
     * alike produces exactly one row rather than two.
     */
    protected static function backfillLookupTable(
        iterable $applianceRows,
        iterable $setRows,
        string $table,
        string $column,
        int $maxLen
    ): void {
        $values = static::distinctExactValues($applianceRows, 'value', $maxLen)
            + static::distinctExactValues($setRows, 'value', $maxLen);

        foreach (array_keys($values) as $value) {
            static::insertIfMissing($table, $column, (string) $value);
        }
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * The distinct exact (case-sensitive) strings in $column, skipping null,
     * empty, whitespace-only and over-length values. Returned as array keys
     * so "1.5HP" and "1.5hp" stay distinct — PHP array keys are compared
     * byte-wise, unlike the utf8mb4_unicode_ci column they came from.
     *
     * @return array<array-key,true>
     */
    protected static function distinctExactValues(iterable $rows, string $column, int $maxLen): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row->{$column} ?? null;

            if (! static::isValidValue($value, $maxLen)) {
                continue;
            }

            $values[$value] = true;
        }

        return $values;
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
     * Deliberately does not filter out soft-deleted rows (no
     * whereNull('deleted_at') here): if an admin has archived this exact
     * value already, re-running the backfill must not resurrect it just
     * because a product still references it. Respecting that admin decision
     * beats re-litigating it on every run — the value simply stays archived
     * until someone restores it by hand.
     */
    protected static function insertIfMissing(string $table, string $column, string $value): void
    {
        $exists = DB::table($table)
            ->whereRaw("BINARY {$column} = ?", [$value])
            ->exists();

        if ($exists) {
            return;
        }

        DB::table($table)->insert([
            $column      => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
