<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the appliance model lookup with a free-text column.
 *
 * An appliance's model is just a label the user types per product; it does not
 * need a shared lookup table. This swaps `appliance_details.model_id` (FK into
 * `model_lookup`) for a plain `model_code` string, and drops `model_lookup`
 * entirely. All `model_id` values are NULL, so no data is migrated.
 *
 * ORDERING: run AFTER 2026_06_25_100000_drop_subcategory_lookup (that migration
 * removes model_lookup.subcategory_id). On rollback, roll THIS migration back
 * first, then the subcategory one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->dropForeign('appliance_details_model_id_foreign');
            $table->dropColumn('model_id');
            $table->string('model_code', 100)->nullable()->after('brand_id');
        });

        Schema::dropIfExists('model_lookup');
    }

    public function down(): void
    {
        // Recreate model_lookup in its post-subcategory-migration shape (no subcategory_id).
        Schema::create('model_lookup', function (Blueprint $table) {
            $table->bigIncrements('model_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('model_code', 100);
            $table->string('variant', 100)->nullable();
            $table->string('capacity', 50)->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['brand_id', 'model_code'], 'model_lookup_brand_model_unique');
            $table->foreign('brand_id', 'model_lookup_brand_id_foreign')
                ->references('brand_id')->on('brand_lookup')
                ->onDelete('set null');
            $table->foreign('status_id', 'model_lookup_status_id_foreign')
                ->references('status_id')->on('status_lookup')
                ->onDelete('set null');
        });

        Schema::table('appliance_details', function (Blueprint $table) {
            $table->dropColumn('model_code');
            $table->unsignedBigInteger('model_id')->nullable()->after('brand_id');
            $table->foreign('model_id', 'appliance_details_model_id_foreign')
                ->references('model_id')->on('model_lookup')
                ->onDelete('set null');
        });
    }
};
