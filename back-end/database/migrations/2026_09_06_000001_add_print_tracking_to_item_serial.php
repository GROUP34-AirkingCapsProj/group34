<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a tag was last sent to a label printer, and how often.
 *
 * All four are nullable or defaulted so every row that already exists reads
 * as "never sent" without a backfill — which is the truth, since nothing
 * recorded printing before this.
 *
 * These record a job leaving for the printer, not a sticker reaching a box.
 * A jammed printer still stamps. The UI says "sent" for that reason, and the
 * reprint action stays available whatever these columns say.
 *
 * last_printed_by follows the table's existing scanned_by convention: an FK
 * that nulls on delete. Removing a user costs the print history its
 * attribution but never blocks the delete or orphans the row.
 *
 * Each column, the index, and the FK are guarded individually — matching
 * 2026_08_30_000003_add_tag_columns_to_item_serial.php — because DDL is
 * non-transactional in MariaDB. An interruption partway through must leave
 * something that can be resumed on the next run, not a coarse guard that
 * reports "done" while the index or FK never landed.
 */
return new class extends Migration
{
    private function hasForeignKey(string $table, string $constraint): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }

    public function up(): void
    {
        if (! Schema::hasTable('item_serial')) {
            return;
        }

        if (! Schema::hasColumn('item_serial', 'first_printed_at')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->timestamp('first_printed_at')->nullable()->after('status_id');
            });
        }

        if (! Schema::hasColumn('item_serial', 'last_printed_at')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->timestamp('last_printed_at')->nullable()->after('first_printed_at');
            });
        }

        if (! Schema::hasColumn('item_serial', 'print_count')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->unsignedInteger('print_count')->default(0)->after('last_printed_at');
            });
        }

        if (! Schema::hasColumn('item_serial', 'last_printed_by')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->unsignedBigInteger('last_printed_by')->nullable()->after('print_count');
            });
        }

        /* The queue's "not yet sent" filter is the page's default view,
           so it runs on every load. */
        if (! Schema::hasIndex('item_serial', 'item_serial_last_printed_at_index')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->index('last_printed_at', 'item_serial_last_printed_at_index');
            });
        }

        if (! $this->hasForeignKey('item_serial', 'item_serial_last_printed_by_foreign')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->foreign('last_printed_by')
                    ->references('user_id')->on('users')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('item_serial')) {
            return;
        }

        if ($this->hasForeignKey('item_serial', 'item_serial_last_printed_by_foreign')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->dropForeign(['last_printed_by']);
            });
        }

        if (Schema::hasIndex('item_serial', 'item_serial_last_printed_at_index')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->dropIndex('item_serial_last_printed_at_index');
            });
        }

        foreach (['first_printed_at', 'last_printed_at', 'print_count', 'last_printed_by'] as $column) {
            if (Schema::hasColumn('item_serial', $column)) {
                Schema::table('item_serial', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
