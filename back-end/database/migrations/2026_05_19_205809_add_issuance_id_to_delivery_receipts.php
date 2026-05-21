<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_receipts', 'issuance_id')) {
                $table->unsignedBigInteger('issuance_id')->nullable()->after('sales_id');
                $table->foreign('issuance_id')
                      ->references('issuance_id')->on('issuances')
                      ->onDelete('cascade');
            }
            if (! Schema::hasColumn('delivery_receipts', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('issuance_id');
                $table->foreign('customer_id')
                      ->references('customer_id')->on('customers')
                      ->onDelete('set null');
            }
            if (! Schema::hasColumn('delivery_receipts', 'location_id')) {
                $table->unsignedBigInteger('location_id')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('delivery_receipts', 'dr_date')) {
                $table->date('dr_date')->nullable()->after('location_id');
            }
            if (! Schema::hasColumn('delivery_receipts', 'total_amount')) {
                $table->decimal('total_amount', 12, 2)->default(0)->after('dr_date');
            }
            if (! Schema::hasColumn('delivery_receipts', 'notes')) {
                $table->text('notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_receipts', function (Blueprint $table) {
            $table->dropForeign(['issuance_id']);
            $table->dropForeign(['customer_id']);
            $table->dropColumn([
                'issuance_id', 'customer_id', 'location_id',
                'dr_date', 'total_amount', 'notes',
            ]);
        });
    }
};
