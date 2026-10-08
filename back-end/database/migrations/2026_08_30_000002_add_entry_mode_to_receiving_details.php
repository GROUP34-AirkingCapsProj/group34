<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * entry_mode records whether the line's *quantity* was verified by
 * scanning supplier barcodes or by a human count.
 *
 * This stays distinct from whether a digital tag exists, because under
 * hybrid tagging a tag always exists regardless of how the line was
 * entered. scanned_barcode is already nullable, but "null because
 * counted" must be distinguishable from "null because legacy" for the
 * audit trail — entry_mode is what makes that distinction explicit.
 *
 * Existing rows default to 'scanned', which is what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('receiving_details', 'entry_mode')) {
            return;
        }

        Schema::table('receiving_details', function (Blueprint $table) {
            $table->enum('entry_mode', ['scanned', 'counted'])->default('scanned')->after('scanned_barcode');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->dropColumn('entry_mode');
        });
    }
};
