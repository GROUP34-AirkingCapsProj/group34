<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the second product type from "consumable" to "installation_material"
 * and renames the consumable_details table to installation_material_details.
 *
 * The product_type enum is widened, data migrated, then narrowed so no row is
 * ever left with an invalid enum value mid-flight.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('appliance','consumable','installation_material') NOT NULL DEFAULT 'appliance'");
        DB::statement("UPDATE products SET product_type = 'installation_material' WHERE product_type = 'consumable'");
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('appliance','installation_material') NOT NULL DEFAULT 'appliance'");

        if (Schema::hasTable('consumable_details') && ! Schema::hasTable('installation_material_details')) {
            Schema::rename('consumable_details', 'installation_material_details');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('installation_material_details') && ! Schema::hasTable('consumable_details')) {
            Schema::rename('installation_material_details', 'consumable_details');
        }

        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('appliance','consumable','installation_material') NOT NULL DEFAULT 'appliance'");
        DB::statement("UPDATE products SET product_type = 'consumable' WHERE product_type = 'installation_material'");
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('appliance','consumable') NOT NULL DEFAULT 'appliance'");
    }
};
