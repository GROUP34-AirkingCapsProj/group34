<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->unsignedInteger('packages_on_hand')->default(0)->after('quantity_on_hand');
        });

        // Backfill from existing data: for consumables (pieces_per_package > 1),
        // derive package count from the stored piece total.
        DB::statement('
            UPDATE inventory i
            JOIN products p ON p.product_id = i.product_id
            SET i.packages_on_hand = FLOOR(i.quantity_on_hand / GREATEST(p.pieces_per_package, 1))
            WHERE p.pieces_per_package > 1
        ');
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropColumn('packages_on_hand');
        });
    }
};
