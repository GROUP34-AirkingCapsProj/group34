<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('variant_lookup')) {
            return;
        }

        Schema::create('variant_lookup', function (Blueprint $table) {
            $table->bigIncrements('variant_id');

            // Width matches appliance_details.variant and product_sets.variant.
            // The rename cascade copies this value into both, so a wider
            // definition here would truncate silently.
            $table->string('variant_name', 100);
            $table->unsignedBigInteger('category_id')->nullable()
                ->comment('NULL = shows for every category');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'variant_name'], 'variant_lookup_scope_idx');

            $table->foreign('category_id')
                ->references('category_id')->on('category_lookup')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_lookup');
    }
};
