<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Comment-only. Retention added a third value, `pruned`, which the original
     * migration's "success | failed" comment predates. No type change: the column
     * is already varchar(10) and was never an enum, so no data is touched.
     *
     * A new migration rather than an edit to 2026_09_18_000001, which has already
     * run on both the live and test databases.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
            . "COMMENT 'success | failed | pruned'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
            . "COMMENT 'success | failed'"
        );
    }
};
