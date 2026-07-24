<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes an abandoned first attempt at set/BOQ selling that exists in the
 * databases but in no migration file and no git history: product_sets (a
 * different shape to the current design), product_set_items, issuance_plan_items,
 * the product_set_id / set_quantity columns on issuances, and the
 * issuance_plan_item_id column on issuance_details.
 *
 * Verified before writing this, on 2026-07-24:
 *   - product_sets and product_set_items held 0 rows
 *   - issuance_plan_items held 7 orphaned rows
 *   - 0 of 13 issuances had a non-null product_set_id
 *   - issuance_details held 0 rows, 0 with a non-null issuance_plan_item_id
 *   - nothing under app/, routes/, or frontend/src referenced any of it
 * Both databases were dumped to storage/db-backups/*_pre_setdrop_20260724.sql first.
 *
 * Drop order matters: children before parents, or the foreign keys refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('issuance_details', 'issuance_plan_item_id')) {
            Schema::table('issuance_details', function (Blueprint $table) {
                $table->dropForeign('issuance_details_issuance_plan_item_id_foreign');
                $table->dropColumn('issuance_plan_item_id');
            });
        }

        Schema::dropIfExists('issuance_plan_items');
        Schema::dropIfExists('product_set_items');

        if (Schema::hasColumn('issuances', 'product_set_id')) {
            Schema::table('issuances', function (Blueprint $table) {
                $table->dropForeign('issuances_product_set_id_foreign');
                $table->dropColumn('product_set_id');
            });
        }

        if (Schema::hasColumn('issuances', 'set_quantity')) {
            Schema::table('issuances', function (Blueprint $table) {
                $table->dropColumn('set_quantity');
            });
        }

        Schema::dropIfExists('product_sets');

        // The phantom rows, so a future `migrate` does not think this schema is present.
        DB::table('migrations')->whereIn('migration', [
            '2026_07_22_100000_create_product_sets_tables',
            '2026_07_22_100001_add_issuance_planning',
        ])->delete();
    }

    /**
     * Deliberately irreversible. The dropped schema exists in no migration file,
     * so there is nothing faithful to recreate; restore from the dated dump in
     * storage/db-backups/ if it is ever needed.
     */
    public function down(): void
    {
        // no-op
    }
};
