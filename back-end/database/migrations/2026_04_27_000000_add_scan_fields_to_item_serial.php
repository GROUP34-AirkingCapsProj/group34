<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_serial', function (Blueprint $table) {
            // Link serial to the receiving session it was created in
            $table->unsignedBigInteger('receiving_id')->nullable()->after('location_id');
            // Track which user performed the scan
            $table->unsignedBigInteger('scanned_by')->nullable()->after('receiving_id');

            $table->foreign('receiving_id')
                ->references('receiving_id')->on('receivings')
                ->onDelete('set null');

            $table->foreign('scanned_by')
                ->references('user_id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('item_serial', function (Blueprint $table) {
            $table->dropForeign(['receiving_id']);
            $table->dropForeign(['scanned_by']);
            $table->dropColumn(['receiving_id', 'scanned_by']);
        });
    }
};
