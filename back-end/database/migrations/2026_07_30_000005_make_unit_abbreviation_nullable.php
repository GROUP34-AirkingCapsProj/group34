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
 * MODIFY COLUMN rather than Blueprint::nullable()->change(). The width,
 * collation and comment are all read from information_schema rather than
 * assumed or hardcoded — MODIFY reconstructs the whole column definition
 * from what's supplied, so leaving any of those out would silently drop it
 * (harmless for this particular column today, but the next lookup column
 * this pattern gets copied onto may carry a COLUMN_COMMENT, e.g.
 * category_lookup.category_type). The whole change is guarded so
 * re-running this migration, or running it against a database where the
 * column is already nullable (as airkingv5.sql now declares it), is a
 * no-op.
 */
return new class extends Migration
{
    private function columnInfo(): ?object
    {
        if (! Schema::hasColumn('unit_lookup', 'unit_abbreviation')) {
            return null;
        }

        return DB::selectOne(
            "SELECT IS_NULLABLE, COLUMN_TYPE, COLLATION_NAME, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unit_lookup' AND COLUMN_NAME = 'unit_abbreviation'"
        );
    }

    private function rebuild(object $column, string $nullability): string
    {
        $sql = "ALTER TABLE unit_lookup MODIFY unit_abbreviation {$column->COLUMN_TYPE}";

        if ($column->COLLATION_NAME) {
            $sql .= " COLLATE {$column->COLLATION_NAME}";
        }

        $sql .= " {$nullability}";

        if ($column->COLUMN_COMMENT !== null && $column->COLUMN_COMMENT !== '') {
            $escaped = str_replace("'", "''", $column->COLUMN_COMMENT);
            $sql .= " COMMENT '{$escaped}'";
        }

        return $sql;
    }

    public function up(): void
    {
        $column = $this->columnInfo();

        if (! $column || $column->IS_NULLABLE === 'YES') {
            return;
        }

        DB::statement($this->rebuild($column, 'NULL'));
    }

    /**
     * Deliberately does NOT force the column back to NOT NULL. A raw-SQL
     * migration with no state of its own cannot tell "up() made this
     * nullable" apart from "this column was already nullable for an
     * unrelated reason" (airkingv5.sql now declares it nullable from a
     * fresh import, independent of whether this migration ever ran) — both
     * look identical at rollback time as IS_NULLABLE = 'YES'. Forcing NOT
     * NULL in that second case would destructively rewrite every existing
     * null abbreviation to '' and then block the exact "no abbreviation"
     * path this migration exists to allow, on a database this migration
     * never actually touched. Silently doing nothing is safer than
     * silently corrupting data.
     */
    public function down(): void
    {
    }
};
