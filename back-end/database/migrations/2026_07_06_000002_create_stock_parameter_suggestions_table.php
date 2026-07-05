<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_parameter_suggestions', function (Blueprint $table) {
            $table->bigIncrements('suggestion_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('current_recommended')->default(0);
            $table->integer('suggested_recommended')->default(0);
            $table->integer('current_safety')->nullable();
            $table->integer('suggested_safety')->nullable();
            $table->integer('current_max')->nullable();
            $table->integer('suggested_max')->nullable();
            $table->json('reason_codes')->nullable();
            $table->string('summary', 255)->nullable();
            $table->string('status', 20)->default('pending'); // pending | applied | dismissed
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('run_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('product_id')->on('products')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('user_id')->on('users')->nullOnDelete();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_parameter_suggestions');
    }
};
