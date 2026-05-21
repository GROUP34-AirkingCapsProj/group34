<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            if (! Schema::hasColumn('issuances', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('location_id');
                $table->foreign('customer_id')
                      ->references('customer_id')->on('customers')
                      ->onDelete('set null');
            }
            if (! Schema::hasColumn('issuances', 'dr_number')) {
                $table->string('dr_number', 50)->nullable()->unique()->after('customer_id');
            }
            if (! Schema::hasColumn('issuances', 'discount_type')) {
                $table->string('discount_type', 30)->nullable()->after('issuance_type')
                      ->comment('none | business | senior | custom');
            }
            if (! Schema::hasColumn('issuances', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0)->after('discount_type');
            }
            if (! Schema::hasColumn('issuances', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->default(0)->after('discount_percent')
                      ->comment('Before discount');
            }
            if (! Schema::hasColumn('issuances', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('issuances', 'total_amount')) {
                $table->decimal('total_amount', 12, 2)->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('issuances', 'notes')) {
                $table->text('notes')->nullable()->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn([
                'customer_id', 'dr_number', 'discount_type',
                'discount_percent', 'subtotal', 'discount_amount',
                'total_amount', 'notes',
            ]);
        });
    }
};
