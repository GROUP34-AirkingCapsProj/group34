<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_records', function (Blueprint $table) {
            $table->bigIncrements('warranty_id');
            $table->unsignedBigInteger('issuance_id')->nullable();
            $table->foreign('issuance_id')
                  ->references('issuance_id')->on('issuances')
                  ->onDelete('cascade');
            $table->unsignedBigInteger('issuance_detail_id')->nullable();
            $table->foreign('issuance_detail_id')
                  ->references('issuance_detail_id')->on('issuance_details')
                  ->onDelete('set null');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')
                  ->references('product_id')->on('products')
                  ->onDelete('set null');
            $table->string('serial_number', 100)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')
                  ->references('customer_id')->on('customers')
                  ->onDelete('set null');
            $table->date('sale_date')->nullable();
            $table->date('warranty_start_date')->nullable();
            $table->date('warranty_end_date')->nullable();
            $table->integer('warranty_period_months')->default(0);
            $table->unsignedBigInteger('status_id')->nullable();
            // 33=Active, 34=Expired, 35=Claimed
            $table->foreign('status_id')
                  ->references('status_id')->on('status_lookup')
                  ->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_records');
    }
};
