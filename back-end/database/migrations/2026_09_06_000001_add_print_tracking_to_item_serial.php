<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_serial') || Schema::hasColumn('item_serial', 'print_count')) {
            return;
        }

        Schema::table('item_serial', function (Blueprint $table) {
            $table->timestamp('first_printed_at')->nullable()->after('status_id');
            $table->timestamp('last_printed_at')->nullable()->after('first_printed_at');
            $table->unsignedInteger('print_count')->default(0)->after('last_printed_at');
            $table->unsignedBigInteger('last_printed_by')->nullable()->after('print_count');

            /* The queue's "not yet sent" filter is the page's default view,
               so it runs on every load. */
            $table->index('last_printed_at', 'item_serial_last_printed_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('item_serial', 'print_count')) {
            return;
        }

        Schema::table('item_serial', function (Blueprint $table) {
            $table->dropIndex('item_serial_last_printed_at_index');
            $table->dropColumn([
                'first_printed_at', 'last_printed_at', 'print_count', 'last_printed_by',
            ]);
        });
    }
};
