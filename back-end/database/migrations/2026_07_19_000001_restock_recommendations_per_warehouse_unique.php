<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            // One row per product per warehouse. persist() replaces the whole
            // batch each run, so existing single-warehouse rows never collide.
            $table->unique(['product_id', 'location_id'], 'restock_reco_product_location_unique');
        });
    }

    public function down(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            $table->dropUnique('restock_reco_product_location_unique');
        });
    }
};
