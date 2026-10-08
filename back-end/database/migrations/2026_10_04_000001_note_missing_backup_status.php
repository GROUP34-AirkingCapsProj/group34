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
     *
     * Per-driver for the reason given on 2026_10_03_000002: this migration is not
     * marked applied by the PostgreSQL baseline, so it executes against Aiven,
     * where MySQL's MODIFY ... COMMENT is a syntax error.
     */
    public function up(): void
    {
        $this->describeStatus('success | failed | pruned | missing');
    }

    public function down(): void
    {
        $this->describeStatus('success | failed | pruned');
    }

    private function describeStatus(string $comment): void
    {
        $quoted = "'" . str_replace("'", "''", $comment) . "'";

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement(
                "ALTER TABLE backup_logs MODIFY status VARCHAR(10) NOT NULL DEFAULT 'success' "
                . "COMMENT {$quoted}"
            ),
            'pgsql' => DB::statement("COMMENT ON COLUMN backup_logs.status IS {$quoted}"),
            default => null,
        };
    }
};
