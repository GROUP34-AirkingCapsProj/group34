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
                $table->string('variant', 60)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['brand_id', 'capacity_rating', 'variant'], 'product_sets_match_idx');
                $table->index('is_active');
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
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_set_lines');
        Schema::dropIfExists('product_sets');
    }
};
