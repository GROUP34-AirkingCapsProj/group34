<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock that exists but is not sellable.
 *
 * Before this table, resolving a write-off deleted the quantity outright: the
 * batch and the location were decremented and the units were simply gone from
 * the database, while physically sitting in a corner of the warehouse. One row
 * here is a quantity of one product, at one location, in one condition,
 * traceable to the incident that put it there.
 *
 * Holds both serialized appliances (serial_id set, quantity 1) and
 * non-serialized installation materials (serial_id null, quantity > 1) — which
 * is why this is a table rather than a status flag on item_serial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_holds', function (Blueprint $table) {
            $table->bigIncrements('hold_id');

            $table->unsignedBigInteger('incident_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            // Which batch it left, so a `restocked` disposition knows where to
            // put it back. Null when the incident named no batch.
            $table->unsignedBigInteger('batch_id')->nullable();
            // The exact unit, for appliances. Null for materials.
            $table->unsignedBigInteger('serial_id')->nullable();

            $table->string('condition', 20);        // missing | defective | returned
            $table->integer('quantity');            // PIECES; always 1 when serial_id is set
            $table->string('status', 20)->default('held');   // held | released

            $table->string('disposition', 30)->nullable();   // null while held
            $table->text('disposition_note')->nullable();
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->dateTime('disposed_at')->nullable();

            $table->timestamps();

            // "What is held at this location right now" is the query the
            // worklist page runs on every load.
            $table->index(['location_id', 'status', 'condition'], 'stock_holds_worklist_idx');
            $table->index('incident_id');
            $table->index('product_id');

            $table->foreign('incident_id')->references('incident_id')->on('incident_reports');
            $table->foreign('product_id')->references('product_id')->on('products');
            $table->foreign('location_id')->references('location_id')->on('locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_holds');
    }
};
