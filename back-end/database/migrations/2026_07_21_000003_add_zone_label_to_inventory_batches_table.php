<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory_batches', 'zone_label')) {
            return;
        }

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->string('zone_label')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('zone_label');
        });
    }
};
