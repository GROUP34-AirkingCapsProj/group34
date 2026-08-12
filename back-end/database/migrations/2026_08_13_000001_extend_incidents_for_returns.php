<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns get a real model, and inbound returns get the column that makes
 * their arithmetic safe.
 *
 * `return_direction` (from_customer|to_supplier) conflated WHO the
 * counterparty was with WHICH WAY the goods travel, and could not express an
 * interbranch transfer at all. Direction is now derived from the counterparty
 * and stored per item as `is_inbound`.
 *
 * is_inbound is STORED, not computed at read time: it decides whether
 * confirmation adds or deducts, and a later edit to the report must not be
 * able to retroactively flip arithmetic that has already run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->string('return_counterparty', 20)->nullable()->after('return_reason');
            $table->string('return_reason_code', 30)->nullable()->after('return_counterparty');
        });

        Schema::table('incident_items', function (Blueprint $table) {
            // What SHOULD have been delivered. Recorded and printed; nothing is
            // raised from it — getting the right unit out is a normal issuance.
            $table->unsignedBigInteger('expected_product_id')->nullable()->after('product_id');
            // What was ORDERED, when the quantity is what went wrong. The
            // delivered figure is the existing `quantity`, so the discrepancy is
            // recorded rather than implied.
            $table->integer('expected_quantity')->nullable()->after('quantity');
            // Inbound goods are physically present but NOT in quantity_on_hand.
            $table->boolean('is_inbound')->default(false)->after('unit_role');

            $table->foreign('expected_product_id')->references('product_id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropColumn(['return_counterparty', 'return_reason_code']);
        });

        Schema::table('incident_items', function (Blueprint $table) {
            $table->dropForeign(['expected_product_id']);
            $table->dropColumn(['expected_product_id', 'expected_quantity', 'is_inbound']);
        });
    }
};
