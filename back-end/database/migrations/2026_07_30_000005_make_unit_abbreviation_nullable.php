<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * unit_lookup.unit_abbreviation is validated as nullable
 * (UnitLookupController::lookupRules()) but the column itself is NOT NULL
 * with no default. Laravel's global ConvertEmptyStringsToNull middleware
 * turns a blank Abbreviation field into null before the controller ever
 * sees it, so the ordinary "add a unit, leave Abbreviation blank" path
 * reaches MySQL as a literal NULL and raises SQLSTATE 1364 -> a 500.
 *
 * doctrine/dbal is not installed in this project, so this uses a raw
 * MODIFY COLUMN rather than Blueprint::nullable()->change(). The width is
 * read from information_schema rather than assumed, and the whole change
 * is guarded so re-running this migration (or running it against a
 * database where the column is already nullable) is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('unit_lookup', 'unit_abbreviation')) {
            return;
        }

        $column = DB::selectOne(
            "SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unit_lookup' AND COLUMN_NAME = 'unit_abbreviation'"
        );

        if (! $column || $column->IS_NULLABLE === 'YES') {
            return;
        }

        // COLUMN_TYPE is e.g. "varchar(10)" — reuse it verbatim so this
        // migration never has to guess or hardcode the column's width.
        DB::statement("ALTER TABLE unit_lookup MODIFY unit_abbreviation {$column->COLUMN_TYPE} NULL");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('unit_lookup', 'unit_abbreviation')) {
            return;
        }

        $column = DB::selectOne(
            "SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unit_lookup' AND COLUMN_NAME = 'unit_abbreviation'"
        );

        if (! $column || $column->IS_NULLABLE === 'NO') {
            return;
        }

        // Reverting would 500 any existing null row on save unless it is
        // backfilled first; leave existing nulls as empty strings rather
        // than silently reintroducing the original bug on rollback.
        DB::statement("UPDATE unit_lookup SET unit_abbreviation = '' WHERE unit_abbreviation IS NULL");
        DB::statement("ALTER TABLE unit_lookup MODIFY unit_abbreviation {$column->COLUMN_TYPE} NOT NULL");
    }
};
