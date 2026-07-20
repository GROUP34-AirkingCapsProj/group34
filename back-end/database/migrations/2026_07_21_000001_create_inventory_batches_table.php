<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_batches')) {
            return;
        }

        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id('batch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('receiving_id')->nullable();
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_remaining')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'location_id', 'received_at'], 'inventory_batches_fifo_idx');

            $table->foreign('product_id')->references('product_id')->on('products')->onDelete('cascade');
            $table->foreign('location_id')->references('location_id')->on('locations')->onDelete('cascade');
            $table->foreign('receiving_id')->references('receiving_id')->on('receivings')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batches');
    }
};
