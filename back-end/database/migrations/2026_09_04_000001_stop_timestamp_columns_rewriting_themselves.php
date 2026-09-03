<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take ON UPDATE CURRENT_TIMESTAMP off five columns that never asked for it.
 *
 * `$table->timestamp('expires_at')` declares a NOT NULL TIMESTAMP with no
 * default. MySQL and MariaDB fill that gap themselves on the first such column
 * in a table, attaching DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
 * The default is harmless — every one of these columns is written explicitly.
 * The ON UPDATE half is not: it rewrites the column to now on ANY later update
 * to the row, however unrelated the change.
 *
 * On password_reset_otps.expires_at that silently broke forgot-password.
 * verify() saves the reset token onto the challenge row, that save drags
 * expires_at forward to now, and the reset() call that follows reads the token
 * as expired and refuses it. Nobody hit it locally because the developer
 * database runs in Europe/Berlin while Laravel runs in UTC, so the rewritten
 * value landed two hours ahead of the app clock and still read as valid. With
 * both clocks in UTC — CI, and any correctly configured server — the flow fails
 * every time.
 *
 * The other four are movement and receipt times: an edit to a claim or a batch
 * movement quietly restamps when it happened.
 *
 * DEFAULT CURRENT_TIMESTAMP is deliberately kept. Dropping it would change what
 * an INSERT that omits the column does, and this migration is only here to stop
 * the rewriting.
 */
return new class extends Migration
{
    /** @var array<int, array{0:string, 1:string}> */
    private array $columns = [
        ['password_reset_otps', 'expires_at'],
        ['password_reset_codes', 'expires_at'],
        ['inventory_batch_movements', 'moved_at'],
        ['warranty_claim_returns', 'received_at'],
        ['warranty_inventory_movements', 'moved_at'],
    ];

    public function up(): void
    {
        foreach ($this->columns as [$table, $column]) {
            if (! $this->hasColumn($table, $column)) {
                continue;
            }

            DB::statement(
                "ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
            );
        }
    }

    public function down(): void
    {
        foreach ($this->columns as [$table, $column]) {
            if (! $this->hasColumn($table, $column)) {
                continue;
            }

            DB::statement(
                "ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL "
                . 'DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            );
        }
    }

    /**
     * These tables arrive from a schema dump rather than a migration chain, so
     * an install that predates one of them should skip it rather than abort the
     * whole migration.
     */
    private function hasColumn(string $table, string $column): bool
    {
        return (bool) DB::selectOne(
            'select 1 as found from information_schema.columns
              where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column],
        );
    }
};
