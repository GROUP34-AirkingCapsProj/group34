<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `awaiting_warranty_claim` is 23 characters and the column is varchar(20).
 *
 * The seven-status vocabulary was introduced without widening the column that
 * stores it, so confirming an incident whose inspector chose `claim_warranty`
 * threw "Data too long for column 'status'" — and it threw MID-TRANSACTION, at
 * the one moment in the whole lifecycle where stock actually moves. The item
 * statuses are plain string codes, not an enum, so widening is all that is
 * needed; 32 leaves room for the next one.
 *
 * Raw SQL rather than $table->string()->change(): change() needs doctrine/dbal
 * on this Laravel/MariaDB pairing and rewrites column attributes the dump
 * already set (NOT NULL, DEFAULT 'quarantined'), which are restated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `incident_items`
             MODIFY `status` VARCHAR(32) NOT NULL DEFAULT 'quarantined'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE `incident_items`
             MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'quarantined'"
        );
    }
};
