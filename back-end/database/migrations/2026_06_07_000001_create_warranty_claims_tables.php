<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The legacy warranty_claims model was never backed by a table. If a
        // stray table exists, drop it so we own a clean, FK-linked schema.
        Schema::dropIfExists('warranty_claim_details');
        Schema::dropIfExists('warranty_claims');

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->bigIncrements('claim_id');
            $table->string('claim_number', 40)->unique();
            $table->unsignedBigInteger('warranty_id');
            $table->foreign('warranty_id')->references('warranty_id')->on('warranty_records')->onDelete('cascade');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('customer_id')->on('customers')->onDelete('set null');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('set null');
            $table->string('serial_number', 100)->nullable();
            $table->enum('claim_type', ['repair', 'replacement']);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->foreign('location_id')->references('location_id')->on('locations')->onDelete('set null');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->foreign('status_id')->references('status_id')->on('status_lookup')->onDelete('set null');
            $table->decimal('parts_cost', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('serial_number');
        });

        Schema::create('warranty_claim_details', function (Blueprint $table) {
            $table->bigIncrements('claim_detail_id');
            $table->unsignedBigInteger('claim_id');
            $table->foreign('claim_id')->references('claim_id')->on('warranty_claims')->onDelete('cascade');
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
            $table->string('serial_number', 100)->nullable();
            $table->integer('quantity_issued')->default(1);
            $table->integer('quantity_in_pieces')->default(1);
            $table->string('quantity_unit', 50)->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('line_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claim_details');
        Schema::dropIfExists('warranty_claims');
    }
};
