<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->unsignedInteger('stock_before')->nullable()->after('zone_label');
            $table->unsignedInteger('quantity_deducted')->nullable()->after('stock_before');
            $table->unsignedInteger('stock_after')->nullable()->after('quantity_deducted');
        });
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropColumn(['stock_before', 'quantity_deducted', 'stock_after']);
        });
    }
};
