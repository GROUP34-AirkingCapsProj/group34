<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PHYSICAL THING on an incident report.
 *
 * Replaces stock_holds. Same idea — a quantity of one product, optionally one
 * serial, carrying a status — but this is the unit of the REPORT, so a
 * split-system aircon is two rows (indoor + outdoor) on one report and the
 * inspector can scrap one while restocking the other. stock_holds pointed at a
 * single product and could not express that.
 *
 * status and outcome are plain string codes, not FKs into status_lookup: the
 * lookup table's ids differ between the dev and test databases, and there is no
 * reason to inherit that hazard for a new column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_items', function (Blueprint $table) {
            $table->bigIncrements('item_id');

            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();

            // The client's form has Model ID and Model OD — an indoor and an
            // outdoor unit on one report.
            $table->string('unit_role', 10)->default('single');   // single|indoor|outdoor

            // Kept verbatim from the sticker, ALONGSIDE the FKs. A defective
            // unit's label may match nothing in the catalogue, and that
            // mismatch is often the incident itself — refusing to record what
            // is physically printed on the box would lose the evidence.
            $table->string('model_code', 100)->nullable();
            $table->string('serial_number', 100)->nullable();

            $table->integer('quantity');                          // PIECES; 1 when serial_id is set
            $table->string('status', 20)->default('quarantined'); // quarantined|available|scrapped|returned|missing
            $table->string('outcome', 30)->nullable();            // the inspector's decision, applied on confirmation
            $table->text('outcome_note')->nullable();

            $table->timestamps();

            // "Which batches are quarantined at this location" runs on every
            // FIFO pick, so it must not table-scan.
            $table->index(['status', 'batch_id'], 'incident_items_quarantine_idx');
            $table->index('incident_id');
            $table->index('product_id');

            $table->foreign('incident_id')->references('incident_id')->on('incident_reports');
            $table->foreign('product_id')->references('product_id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_items');
    }
};
