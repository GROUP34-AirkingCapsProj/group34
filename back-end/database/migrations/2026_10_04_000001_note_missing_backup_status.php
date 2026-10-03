<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Comment-only. `backup:reconcile` adds a fourth value, `missing`, for a row
     * whose snapshot file is not on disk for reasons retention never caused -- a
     * deleted folder, a moved storage path, or a snapshot recorded by a different
     * install that shared this database.
     *
     * Separate from `pruned` on purpose: pruned is a deliberate, unremarkable
     * deletion, missing is an anomaly worth seeing.
     *
     * No type change: already varchar(10), never an enum, and `missing` is 7
     * characters.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
            . "COMMENT 'success | failed | pruned | missing'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
            . "COMMENT 'success | failed | pruned'"
        );
    }
};
