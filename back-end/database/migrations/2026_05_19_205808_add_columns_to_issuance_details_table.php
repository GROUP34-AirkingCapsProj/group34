<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            if (! Schema::hasColumn('issuance_details', 'serial_number')) {
                $table->string('serial_number', 100)->nullable()->after('product_id')
                      ->comment('Appliances only — the scanned serial');
            }
            if (! Schema::hasColumn('issuance_details', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->default(0)->after('serial_number');
            }
            if (! Schema::hasColumn('issuance_details', 'quantity_unit')) {
                $table->string('quantity_unit', 30)->nullable()->after('quantity_issued')
                      ->comment('unit | piece | meter | roll | kg | liter');
            }
            if (! Schema::hasColumn('issuance_details', 'quantity_in_pieces')) {
                $table->integer('quantity_in_pieces')->default(0)->after('quantity_unit')
                      ->comment('Actual pieces deducted from inventory');
            }
            if (! Schema::hasColumn('issuance_details', 'unit_price_per_piece')) {
                $table->decimal('unit_price_per_piece', 10, 4)->default(0)->after('quantity_in_pieces')
                      ->comment('unit_price / pieces_per_package for consumables');
            }
            if (! Schema::hasColumn('issuance_details', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->default(0)->after('unit_price_per_piece');
            }
        });
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropColumn([
                'serial_number', 'unit_price', 'quantity_unit',
                'quantity_in_pieces', 'unit_price_per_piece', 'subtotal',
            ]);
        });
    }
};
