<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->unsignedInteger('warranty_value')->default(0)->after('warranty_period_months');
            $table->string('warranty_unit', 10)->default('month')->after('warranty_value');
        });

        Schema::table('consumable_details', function (Blueprint $table) {
            $table->unsignedInteger('warranty_value')->default(0)->after('is_serialized');
            $table->string('warranty_unit', 10)->default('month')->after('warranty_value');
        });

        // Migrate existing appliance warranty data.
        // appliance_detail_id=2 (Condura 3HP): 24 months → 2 years
        DB::table('appliance_details')->where('appliance_detail_id', 2)
            ->update(['warranty_value' => 2, 'warranty_unit' => 'year']);

        // appliance_detail_id=3 (TCL 1HP): 22 months → 22 months (not a clean year multiple)
        DB::table('appliance_details')->where('appliance_detail_id', 3)
            ->update(['warranty_value' => 22, 'warranty_unit' => 'month']);

        // appliance_detail_id=1 (LG 1HP): warranty_period_months=0 stays at defaults (0, month)
    }

    public function down(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->dropColumn(['warranty_value', 'warranty_unit']);
        });

        Schema::table('consumable_details', function (Blueprint $table) {
            $table->dropColumn(['warranty_value', 'warranty_unit']);
        });
    }
};
