<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the issuable-units feature.
     *
     * Order is irrelevant: neither table references the other. Both reference
     * products and unit_lookup, and DROP TABLE removes their foreign keys
     * automatically — including the last two RESTRICT referents on
     * unit_lookup. From here on, deleting a unit that is still in use is
     * guarded only by UnitLookupController::forceDestroy's usage check.
     */
    public function up(): void
    {
        Schema::dropIfExists('issuable_unit_preset');
        Schema::dropIfExists('product_issuable_units');
    }

    /**
     * Recreates the schema but NOT the data — the rows are gone the moment
     * up() runs. This exists so a rollback does not leave the migrations
     * table claiming a state the database is not in; it is not a restore.
     * To recover data, re-import the mysqldump taken before up() was run.
     */
    public function down(): void
    {
        if (! Schema::hasTable('product_issuable_units')) {
            Schema::create('product_issuable_units', function (Blueprint $t) {
                $t->bigIncrements('issuable_unit_id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('unit_id');
                $t->string('label', 100);
                $t->decimal('conversion_factor', 10, 6)->default(1.000000);
                $t->boolean('is_whole_package')->default(false);
                $t->unsignedTinyInteger('sort_order')->default(0);
                $t->boolean('show_as_quick_action')->default(true);
                $t->timestamps();

                $t->unique(['product_id', 'label'], 'unique_product_label');
                $t->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
                $t->foreign('unit_id')->references('unit_id')->on('unit_lookup')->onDelete('restrict');
            });
        }

        if (! Schema::hasTable('issuable_unit_preset')) {
            Schema::create('issuable_unit_preset', function (Blueprint $t) {
                $t->bigIncrements('preset_id');
                $t->string('label', 100);
                $t->unsignedBigInteger('unit_id');
                $t->decimal('default_conversion_factor', 10, 6)->default(1.000000);
                $t->boolean('is_whole_package')->default(false);
                $t->text('description')->nullable();
                $t->unsignedTinyInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();

                $t->index('label', 'issuable_unit_preset_label_idx');
                $t->foreign('unit_id')->references('unit_id')->on('unit_lookup')->onDelete('restrict');
            });
        }
    }
};
