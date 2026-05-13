<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scan_corrections', function (Blueprint $table) {
            $table->bigIncrements('correction_id');

            // Link to the adjustment header
            $table->unsignedBigInteger('adjustment_id')->nullable();
            $table->foreign('adjustment_id')
                  ->references('adjustment_id')
                  ->on('adjustments')
                  ->onDelete('cascade');

            // Which PO and receiving session this correction relates to
            $table->unsignedBigInteger('po_id')->nullable();
            $table->foreign('po_id')
                  ->references('po_id')
                  ->on('purchase_orders')
                  ->onDelete('set null');

            $table->unsignedBigInteger('receiving_id')->nullable();
            $table->foreign('receiving_id')
                  ->references('receiving_id')
                  ->on('receivings')
                  ->onDelete('set null');

            // The original (wrong) scan
            $table->string('original_barcode', 100)->nullable()
                  ->comment('The barcode that was scanned incorrectly');
            $table->unsignedBigInteger('original_product_id')->nullable()
                  ->comment('Product the barcode was originally matched to');
            $table->foreign('original_product_id')
                  ->references('product_id')
                  ->on('products')
                  ->onDelete('set null');

            // The corrected scan
            $table->string('corrected_barcode', 100)->nullable()
                  ->comment('The correct barcode (if barcode changed)');
            $table->unsignedBigInteger('corrected_product_id')->nullable()
                  ->comment('Product the barcode should have been matched to');
            $table->foreign('corrected_product_id')
                  ->references('product_id')
                  ->on('products')
                  ->onDelete('set null');

            // Values: 'wrong_product' | 'wrong_quantity' | 'duplicate_scan'
            //         | 'wrong_serial' | 'void_scan'
            $table->string('correction_type', 50)->nullable();

            // Quantity changes from this correction
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity_after')->default(0);

            // Serial number tracking (for appliances)
            $table->string('serial_number_before', 100)->nullable();
            $table->string('serial_number_after', 100)->nullable();

            // Link to the specific receiving_detail row that was corrected
            $table->unsignedBigInteger('receiving_detail_id')->nullable();
            $table->foreign('receiving_detail_id')
                  ->references('receiving_detail_id')
                  ->on('receiving_details')
                  ->onDelete('set null');

            // Link to the item_serial row that was affected (appliances)
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->foreign('serial_id')
                  ->references('serial_id')
                  ->on('item_serial')
                  ->onDelete('set null');

            $table->text('reason')->nullable()
                  ->comment('Why this correction was made');

            $table->unsignedBigInteger('corrected_by')->nullable();
            $table->foreign('corrected_by')
                  ->references('user_id')
                  ->on('users')
                  ->onDelete('set null');

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')
                  ->references('user_id')
                  ->on('users')
                  ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_corrections');
    }
};
