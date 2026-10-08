<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * count_unit records how this product is physically counted when a
 * delivery arrives unbarcoded and staff must count it by hand.
 *
 * It drives two things only — what the receiving screen asks for
 * ("how many packages?" vs "how many pieces?") and how many digital
 * tags a *counted* receipt line mints. It never affects storage;
 * pieces are always what gets stored (see Inventory::setPieces()).
 *
 * Copper tubing is counted by the roll (package); bolts are counted
 * by the piece (piece).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('installation_material_details', 'count_unit')) {
            return;
        }

        Schema::table('installation_material_details', function (Blueprint $table) {
            $table->enum('count_unit', ['package', 'piece'])->default('package')->after('package_unit');
        });
    }

    public function down(): void
    {
        Schema::table('installation_material_details', function (Blueprint $table) {
            $table->dropColumn('count_unit');
        });
    }
};
