<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batch_movements', function (Blueprint $table) {
            $table->bigIncrements('batch_movement_id');
            $table->unsignedBigInteger('batch_id');
            $table->string('movement_type', 20);
            $table->unsignedBigInteger('issuance_id')->nullable();
            $table->unsignedBigInteger('transfer_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('from_location_id');
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->integer('quantity');
            $table->string('serial_number')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->foreign('batch_id')->references('batch_id')->on('inventory_batches')->cascadeOnDelete();
            $table->index(['batch_id', 'moved_at']);
            $table->index(['transfer_id', 'product_id']);
            $table->index(['issuance_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_batch_movements');
    }
};
