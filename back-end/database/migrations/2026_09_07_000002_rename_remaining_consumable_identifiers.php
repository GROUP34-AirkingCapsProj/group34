<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finishes the consumable -> installation material rename started in
 * 2026_07_08_000000. That migration moved the table and the products.product_type
 * enum; two identifiers were left behind:
 *
 *   1. installation_material_details.consumable_detail_id (the primary key)
 *   2. pending_products.registration_kind, whose 'consumable' value and column
 *      default still named the old product type.
 *
 * "Consumable" remains a valid *category* type (see 2026_08_01_000002) and is
 * deliberately untouched here — only the product-type sense is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('installation_material_details', 'consumable_detail_id')) {
            DB::statement('ALTER TABLE installation_material_details CHANGE consumable_detail_id installation_material_detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }

        if (Schema::hasColumn('pending_products', 'registration_kind')) {
            DB::statement("ALTER TABLE pending_products MODIFY COLUMN registration_kind VARCHAR(24) NOT NULL DEFAULT 'installation_material'");
            DB::table('pending_products')
                ->where('registration_kind', 'consumable')
                ->update(['registration_kind' => 'installation_material']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('installation_material_details', 'installation_material_detail_id')) {
            DB::statement('ALTER TABLE installation_material_details CHANGE installation_material_detail_id consumable_detail_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }

        if (Schema::hasColumn('pending_products', 'registration_kind')) {
            DB::table('pending_products')
                ->where('registration_kind', 'installation_material')
                ->update(['registration_kind' => 'consumable']);
            DB::statement("ALTER TABLE pending_products MODIFY COLUMN registration_kind VARCHAR(24) NOT NULL DEFAULT 'consumable'");
        }
    }
};
