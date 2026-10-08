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
     *
     * Written per driver because the two spell a column comment differently, and
     * this migration is one of only four not marked applied by the PostgreSQL
     * baseline -- so unlike the MySQL-only migrations in the chain's history, it
     * really does execute against Aiven. MySQL carries the comment on the column
     * definition, so restating the full definition is the only way to change it;
     * PostgreSQL has a separate COMMENT ON statement and no MODIFY at all.
     */
    public function up(): void
    {
        $this->describeStatus('success | failed | pruned');
    }

    public function down(): void
    {
        $this->describeStatus('success | failed');
    }

    /**
     * Engines without column comments (SQLite) fall through doing nothing: the
     * comment is documentation, so its absence costs a reader a hint and the
     * schema nothing.
     */
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
