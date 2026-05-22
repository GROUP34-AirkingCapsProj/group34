<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_issuable_units', function (Blueprint $t) {
            $t->bigIncrements('issuable_unit_id');

            $t->unsignedBigInteger('product_id');
            $t->foreign('product_id')
              ->references('product_id')->on('products')
              ->onDelete('cascade');

            $t->unsignedBigInteger('unit_id');
            $t->foreign('unit_id')
              ->references('unit_id')->on('unit_lookup')
              ->onDelete('restrict');

            $t->string('label', 100);

            $t->decimal('conversion_factor', 10, 6)->default(1.000000)
              ->comment('Inventory pieces deducted per 1 of this issuable unit');

            $t->boolean('is_whole_package')->default(false)
              ->comment('True = this represents 1 full package');

            $t->unsignedTinyInteger('sort_order')->default(0);

            $t->boolean('show_as_quick_action')->default(true)
              ->comment('False = available in dropdown only, not as a button');

            $t->timestamps();

            $t->unique(['product_id', 'label'], 'unique_product_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_issuable_units');
    }
};
