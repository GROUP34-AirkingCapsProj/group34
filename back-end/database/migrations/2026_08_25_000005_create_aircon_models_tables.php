<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An aircon model names a combination of product rows — typically one
     * indoor and one outdoor unit, but a multi-split may be three indoor and
     * one outdoor. The member products stay ordinary products with their own
     * price, stock, serials and supplier identity; the model only groups them.
     *
     * Each side has to stay a first-class product because a single side is
     * genuinely bought and sold on its own for replacements, and because the
     * two sides' stock counts legitimately drift — a short delivery, a damaged
     * carton, a warranty swap of one unit.
     *
     * This is deliberately NOT product_sets. That table was stripped of
     * set_code, set_name and its single brand_id by
     * 2026_07_27_000001_redesign_product_sets_multibrand so it could serve as
     * a brand-agnostic job BOQ template matched on capacity and variant. A
     * manufacturer's product identity is a different thing, and merging the
     * two would undo that migration's intent.
     */
    public function up(): void
    {
        Schema::create('aircon_models', function (Blueprint $table) {
            $table->bigIncrements('aircon_model_id');
            $table->string('model_name', 191);
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('ac_type_id')->nullable();

            // Widths match appliance_details.capacity_rating and .variant so a
            // value copied from a member product cannot be truncated here.
            $table->string('capacity_rating', 20)->nullable();
            $table->string('variant', 100)->nullable();

            // Overrides the AC type's default. Read when saving a model: true
            // requires at least one indoor and one outdoor unit line, false
            // requires exactly one complete line.
            $table->boolean('is_two_piece')->default(true);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('model_name');

            $table->foreign('brand_id')->references('brand_id')->on('brand_lookup')->nullOnDelete();
            $table->foreign('ac_type_id')->references('ac_type_id')->on('ac_type_lookup')->nullOnDelete();
        });

        Schema::create('aircon_model_units', function (Blueprint $table) {
            $table->bigIncrements('aircon_model_unit_id');
            $table->unsignedBigInteger('aircon_model_id');
            $table->unsignedBigInteger('product_id');

            // 3 for the indoor line of a 3:1 multi-split.
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // One line per product per model. Wanting the same product twice
            // means raising its quantity, not adding a second line.
            $table->unique(['aircon_model_id', 'product_id']);

            // There is deliberately NO unit_role column here.
            //
            // A line points at a product, and that product's
            // appliance_details.unit_role already records which side it is.
            // Storing the role in both places would give two sources of truth
            // that can disagree, and an invariant to police on every write. It
            // also means a lone outdoor unit sitting in inventory with no
            // model at all still reads correctly.
            //
            // AirconModelUnit::unitRole() reads it through the product.

            $table->foreign('aircon_model_id')
                ->references('aircon_model_id')
                ->on('aircon_models')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('product_id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircon_model_units');
        Schema::dropIfExists('aircon_models');
    }
};
