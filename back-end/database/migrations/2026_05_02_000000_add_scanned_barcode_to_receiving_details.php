<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->string('scanned_barcode', 191)->nullable()->after('condition');
            $table->index('scanned_barcode', 'rd_scanned_barcode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_details', function (Blueprint $table) {
            $table->dropIndex('rd_scanned_barcode_idx');
            $table->dropColumn('scanned_barcode');
        });
    }
};
