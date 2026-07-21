<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('issuance_details', 'receiving_id')) {
            return;
        }

        Schema::table('issuance_details', function (Blueprint $table) {
            $table->unsignedBigInteger('receiving_id')->nullable()->after('serial_number');
            $table->foreign('receiving_id')->references('receiving_id')->on('receivings')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropForeign(['receiving_id']);
            $table->dropColumn('receiving_id');
        });
    }
};
