<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warranty_claim_details', function (Blueprint $table) {
            $table->integer('quantity_requested')->default(0)->after('serial_number');
            $table->unsignedBigInteger('product_set_id')->nullable()->after('product_id');
            $table->string('line_source', 20)->default('custom')->after('product_set_id');
            $table->foreign('product_set_id')->references('set_id')->on('product_sets')->nullOnDelete();
        });

        Schema::create('warranty_claim_returns', function (Blueprint $table) {
            $table->bigIncrements('return_id');
            $table->unsignedBigInteger('claim_id')->unique();
            $table->unsignedBigInteger('product_id');
            $table->string('serial_number', 100)->index();
            $table->unsignedBigInteger('location_id');
            $table->string('holding_status', 30)->default('warranty_holding');
            $table->timestamp('received_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_disposition', 30)->nullable();
            $table->unsignedBigInteger('replacement_warranty_id')->nullable();
            $table->timestamps();

            $table->foreign('claim_id')->references('claim_id')->on('warranty_claims')->cascadeOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
            $table->foreign('location_id')->references('location_id')->on('locations')->restrictOnDelete();
            $table->foreign('replacement_warranty_id')->references('warranty_id')->on('warranty_records')->nullOnDelete();
        });

        Schema::create('warranty_inventory_movements', function (Blueprint $table) {
            $table->bigIncrements('movement_id');
            $table->unsignedBigInteger('claim_id');
            $table->unsignedBigInteger('claim_detail_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->string('movement_type', 40);
            $table->integer('quantity');
            $table->string('serial_number', 100)->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->foreign('claim_id')->references('claim_id')->on('warranty_claims')->cascadeOnDelete();
            $table->foreign('claim_detail_id')->references('claim_detail_id')->on('warranty_claim_details')->nullOnDelete();
            $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
            $table->foreign('location_id')->references('location_id')->on('locations')->restrictOnDelete();
            $table->index(['claim_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_inventory_movements');
        Schema::dropIfExists('warranty_claim_returns');
        Schema::table('warranty_claim_details', function (Blueprint $table) {
            $table->dropForeign(['product_set_id']);
            $table->dropColumn(['quantity_requested', 'product_set_id', 'line_source']);
        });
    }
};
