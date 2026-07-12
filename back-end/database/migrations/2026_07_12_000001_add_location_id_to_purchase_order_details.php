<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('purchase_order_details', 'location_id')) {
            Schema::table('purchase_order_details', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')->nullable()->after('po_id');
                $table->foreign('location_id')
                    ->references('location_id')->on('locations')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_order_details', 'location_id')) {
            Schema::table('purchase_order_details', function (Blueprint $table) {
                $table->dropForeign(['location_id']);
                $table->dropColumn('location_id');
            });
        }
    }
};
