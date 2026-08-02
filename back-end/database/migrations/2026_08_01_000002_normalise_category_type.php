<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalises category_type to exactly two values.
     *
     * ItemManagement.jsx currently filters categories with `=== 'appliance'`
     * and `=== 'consumable'`; the second filter is being changed to
     * `=== 'installation_material'` in a companion frontend change (Task 2 of
     * the same plan). Until that lands, this migration and that frontend fix
     * are one deploy unit — deploying this without it would make every
     * 'installation_material' row (both the ones converted here and any
     * created afterwards by the now-fixed ProductController) briefly
     * unselectable, exactly the defect being fixed. 'consumable' is the old
     * name for installation materials; 'accessory' was never handled by
     * either filter. 'installation_material' was chosen because it matches
     * products.product_type, which already uses that word.
     */
    public function up(): void
    {
        DB::table('category_lookup')
            ->whereIn('category_type', ['consumable', 'accessory'])
            ->update(['category_type' => 'installation_material']);
    }

    /**
     * Deliberately does nothing.
     *
     * A value rename cannot be reversed safely. This migration has no record
     * of which rows were 'consumable' and which were 'accessory', and by the
     * time anyone rolls back, ProductController and the Item Lookups page are
     * both creating 'installation_material' rows of their own. Rewriting every
     * such row to 'consumable' would corrupt those and leave the frontend —
     * which filters on 'installation_material' — unable to display them.
     *
     * To revert, restore category_lookup from a backup taken before up() ran.
     */
    public function down(): void
    {
    }
};
