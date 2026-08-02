<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalises category_type to exactly two values.
     *
     * ItemManagement.jsx filters categories with `=== 'appliance'` and
     * `=== 'installation_material'`. Anything else matches neither and the
     * category becomes unselectable in both dropdowns. 'consumable' is the
     * old name for installation materials; 'accessory' was never handled by
     * either filter. Using 'installation_material' matches
     * products.product_type, which already uses that word.
     */
    public function up(): void
    {
        DB::table('category_lookup')
            ->whereIn('category_type', ['consumable', 'accessory'])
            ->update(['category_type' => 'installation_material']);
    }

    /**
     * Reverses the rename. This CANNOT distinguish the rows that were
     * 'accessory' before up() ran — they come back as 'consumable'. That is
     * the honest limit of a value migration with no record of the prior value.
     */
    public function down(): void
    {
        DB::table('category_lookup')
            ->where('category_type', 'installation_material')
            ->update(['category_type' => 'consumable']);
    }
};
