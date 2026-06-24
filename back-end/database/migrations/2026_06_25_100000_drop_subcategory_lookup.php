<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the abandoned `subcategory_lookup` feature.
 *
 * The table is empty and has no frontend consumer. Its only inbound link is
 * `model_lookup.subcategory_id` (FK `model_lookup_subcategory_id_foreign`,
 * ON DELETE SET NULL), which is also unused. `model_lookup` itself is kept.
 *
 * up(): drop the inbound FK + orphaned column on model_lookup, then the table.
 * down(): recreate the table exactly as it was, then restore the column + FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_lookup', function (Blueprint $table) {
            $table->dropForeign('model_lookup_subcategory_id_foreign');
            $table->dropColumn('subcategory_id');
        });

        Schema::dropIfExists('subcategory_lookup');
    }

    public function down(): void
    {
        Schema::create('subcategory_lookup', function (Blueprint $table) {
            $table->bigIncrements('subcategory_id');
            $table->unsignedBigInteger('category_id');
            $table->string('subcategory_name', 100);
            $table->boolean('is_serialized')->default(false);
            $table->timestamps();

            $table->unique(['category_id', 'subcategory_name'], 'subcategory_lookup_cat_name_unique');
            $table->foreign('category_id', 'subcategory_lookup_category_id_foreign')
                ->references('category_id')->on('category_lookup')
                ->onDelete('cascade');
        });

        Schema::table('model_lookup', function (Blueprint $table) {
            $table->unsignedBigInteger('subcategory_id')->nullable()->after('brand_id');
            $table->foreign('subcategory_id', 'model_lookup_subcategory_id_foreign')
                ->references('subcategory_id')->on('subcategory_lookup')
                ->onDelete('set null');
        });
    }
};
