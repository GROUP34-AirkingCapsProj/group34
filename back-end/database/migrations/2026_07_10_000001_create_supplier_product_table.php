<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `supplier_product` table exists in App\Models\SupplierProduct and in
 * the original schema dump (airking_cleans.sql) but was never applied to
 * either the dev or test databases. Warehouse-only procurement (Task 3) needs
 * it to enforce supplier-product integrity on PR creation, so this migration
 * creates it now, matching the dump's structure exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_product')) {
            return;
        }

        Schema::create('supplier_product', function (Blueprint $table) {
            $table->id('supplier_prod_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('product_price', 10, 2)->nullable();
            $table->string('currency', 10)->default('PHP');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id'], 'supplier_product_sup_prod_unique');

            $table->foreign('supplier_id')->references('supplier_id')->on('suppliers')->onDelete('cascade');
            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
            $table->foreign('status_id')->references('status_id')->on('status_lookup')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product');
    }
};
