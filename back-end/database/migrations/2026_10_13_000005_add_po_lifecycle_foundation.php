<?php

use App\Services\PoLifecycleBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('lifecycle_status', 30)->nullable()->index();
            $table->date('original_expected_delivery_date')->nullable();
            $table->unsignedBigInteger('replacement_for_po_id')->nullable()->index();
        });
        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->string('order_unit', 20)->nullable();
            $table->unsignedInteger('pieces_per_order_unit')->nullable();
            $table->string('quantity_snapshot_source', 30)->nullable();
            // Integer pieces allow closing the missing contents of a partial package.
            $table->unsignedBigInteger('short_closed_quantity')->default(0);
            $table->unsignedBigInteger('replacement_for_po_detail_id')->nullable()->index();
        });
        Schema::create('po_lifecycle_exceptions', function (Blueprint $table) {
            $table->bigIncrements('exception_id');
            // No cascading FKs: reconciliation evidence must outlive its subject.
            $table->unsignedBigInteger('po_id')->nullable()->index();
            $table->unsignedBigInteger('po_detail_id')->nullable();
            $table->unsignedBigInteger('receiving_detail_id')->nullable();
            $table->string('code', 60);
            $table->json('context');
            $table->timestamp('created_at');
        });

        app(PoLifecycleBackfillService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('po_lifecycle_exceptions');
        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->dropColumn(['order_unit', 'pieces_per_order_unit', 'quantity_snapshot_source',
                'short_closed_quantity', 'replacement_for_po_detail_id']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_status', 'original_expected_delivery_date', 'replacement_for_po_id']);
        });
    }
};
