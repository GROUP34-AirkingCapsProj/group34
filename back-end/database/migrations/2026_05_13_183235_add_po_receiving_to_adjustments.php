<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('po_id')->nullable()->after('location_id');
            $table->foreign('po_id')
                  ->references('po_id')
                  ->on('purchase_orders')
                  ->onDelete('set null');

            $table->unsignedBigInteger('receiving_id')->nullable()->after('po_id');
            $table->foreign('receiving_id')
                  ->references('receiving_id')
                  ->on('receivings')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropForeign(['po_id']);
            $table->dropForeign(['receiving_id']);
            $table->dropColumn(['po_id', 'receiving_id']);
        });
    }
};
