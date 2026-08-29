<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * batch_id is explicit rather than derived through receiving_id,
 * because transfer-created batches carry a null receiving_id and the
 * derivation would silently fail there. It is the anchor FIFO
 * enforcement reads.
 *
 * supplier_barcode lets one row carry BOTH identifiers for an item
 * that arrived labelled — the generated INT-... serial and the
 * manufacturer's code. Two rows would double-count against the batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('item_serial', 'batch_id')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('receiving_id');
            });

            Schema::table('item_serial', function (Blueprint $table) {
                $table->foreign('batch_id')->references('batch_id')->on('inventory_batches')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('item_serial', 'supplier_barcode')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->string('supplier_barcode', 191)->nullable()->after('serial_number');
            });

            Schema::table('item_serial', function (Blueprint $table) {
                $table->index('supplier_barcode', 'item_serial_supplier_barcode_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('item_serial', 'batch_id')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->dropForeign(['batch_id']);
            });

            Schema::table('item_serial', function (Blueprint $table) {
                $table->dropColumn('batch_id');
            });
        }

        if (Schema::hasColumn('item_serial', 'supplier_barcode')) {
            Schema::table('item_serial', function (Blueprint $table) {
                $table->dropIndex('item_serial_supplier_barcode_idx');
            });

            Schema::table('item_serial', function (Blueprint $table) {
                $table->dropColumn('supplier_barcode');
            });
        }
    }
};
