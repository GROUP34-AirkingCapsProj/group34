<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_sets')) {
            Schema::create('product_sets', function (Blueprint $table) {
                $table->id('set_id');
                $table->string('set_code', 60)->unique();
                $table->string('set_name', 191);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('brand_id')->nullable();
                $table->string('capacity_rating', 60)->nullable();
                // 100 to match appliance_details.variant exactly. This column is
                // compared to it for equality when suggesting a set for an aircon,
                // so a narrower width would silently truncate a long variant label
                // and the match would simply stop working, with no error.
                $table->string('variant', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['brand_id', 'capacity_rating', 'variant'], 'product_sets_match_idx');
                $table->index('is_active');

                $table->foreign('brand_id')->references('brand_id')->on('brand_lookup')->nullOnDelete();
                $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('product_set_lines')) {
            Schema::create('product_set_lines', function (Blueprint $table) {
                $table->id('set_line_id');
                $table->unsignedBigInteger('set_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('quantity', 10, 3);
                $table->unsignedBigInteger('issuable_unit_id')->nullable();
                $table->string('spec_note', 191)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index('set_id');
                $table->index('product_id');

                // Lines are meaningless without their header, so they follow it out.
                $table->foreign('set_id')->references('set_id')->on('product_sets')->cascadeOnDelete();
                // A product in use by a BOQ must not vanish underneath it.
                $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
                $table->foreign('issuable_unit_id')->references('issuable_unit_id')
                      ->on('product_issuable_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_set_lines');
        Schema::dropIfExists('product_sets');
    }
};
