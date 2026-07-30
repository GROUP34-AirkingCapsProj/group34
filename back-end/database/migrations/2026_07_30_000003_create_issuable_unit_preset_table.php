<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('issuable_unit_preset')) {
            return;
        }

        Schema::create('issuable_unit_preset', function (Blueprint $table) {
            $table->bigIncrements('preset_id');

            // Types deliberately mirror product_issuable_units so applying a
            // preset to a product needs no conversion.
            $table->string('label', 100);
            $table->unsignedBigInteger('unit_id');
            $table->decimal('default_conversion_factor', 10, 6)->default(1.000000)
                ->comment('Inventory pieces per 1 of this issuable unit');
            $table->boolean('is_whole_package')->default(false);

            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('label', 'issuable_unit_preset_label_idx');

            $table->foreign('unit_id')
                ->references('unit_id')->on('unit_lookup')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuable_unit_preset');
    }
};
