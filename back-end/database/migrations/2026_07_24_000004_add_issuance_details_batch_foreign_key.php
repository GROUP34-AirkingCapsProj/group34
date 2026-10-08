<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the foreign key that 2026_07_24_000002 gave an index but never a
 * constraint: issuance_details.batch_id -> inventory_batches.batch_id.
 *
 * Additive rather than folded into 000002, because that migration is already
 * applied and rolling it back would drop six columns from a live table to
 * recreate them identically — all risk, no benefit.
 *
 * ON DELETE SET NULL: a batch can be purged while the issuance line that drew
 * from it must survive as a historical record. Losing the batch pointer is
 * acceptable; losing the issued line is not.
 */
return new class extends Migration
{
    private const FK = 'issuance_details_batch_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('issuance_details', 'batch_id') || $this->foreignKeyExists()) {
            return;
        }

        // Any pre-existing rows pointing at a batch that no longer exists would
        // block the constraint; null them first so the migration is safe to run
        // against a database with history.
        DB::statement(
            'UPDATE issuance_details d
             LEFT JOIN inventory_batches b ON b.batch_id = d.batch_id
             SET d.batch_id = NULL
             WHERE d.batch_id IS NOT NULL AND b.batch_id IS NULL'
        );

        Schema::table('issuance_details', function (Blueprint $table) {
            $table->foreign('batch_id', self::FK)
                  ->references('batch_id')->on('inventory_batches')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->foreignKeyExists()) {
            return;
        }

        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropForeign(self::FK);
        });
    }

    /** Laravel has no hasForeign(), so ask information_schema directly. */
    private function foreignKeyExists(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'issuance_details')
            ->where('CONSTRAINT_NAME', self::FK)
            ->exists();
    }
};
