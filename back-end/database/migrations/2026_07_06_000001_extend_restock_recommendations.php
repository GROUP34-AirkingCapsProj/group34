<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            $table->string('priority', 10)->default('MEDIUM')->after('reason');
            $table->json('reason_codes')->nullable()->after('priority');
            $table->decimal('avg_daily_demand', 10, 3)->default(0)->after('reason_codes');
            $table->decimal('demand_trend', 6, 3)->nullable()->after('avg_daily_demand');
            $table->decimal('days_of_cover', 8, 1)->nullable()->after('demand_trend');
            $table->decimal('lead_time_days', 6, 1)->default(7)->after('days_of_cover');
            $table->integer('on_order_quantity')->default(0)->after('lead_time_days');
            $table->timestamp('run_at')->nullable()->after('on_order_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('restock_recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'priority', 'reason_codes', 'avg_daily_demand', 'demand_trend',
                'days_of_cover', 'lead_time_days', 'on_order_quantity', 'run_at',
            ]);
        });
    }
};
