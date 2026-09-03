<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One delivery being staged before it is posted to inventory.
 *
 * Receiving used to post on every scan, and nothing could undo one: a clerk who
 * scanned a TCL aircon while the wrong PO line was selected had received an
 * aircon as bulk material, permanently. A draft is the review step that was
 * missing — goods are described here first, and only Submit moves stock.
 *
 * The draft lives in the database rather than in browser state on purpose. A
 * half-counted truckload must survive a closed tab, a dead battery, and a shift
 * change, and a second clerk on the same PO must be able to see that a delivery
 * is already being staged rather than starting a rival list.
 *
 * `status` is what makes "one draft per PO+warehouse" true only of OPEN drafts.
 * Submitted and discarded rows are kept as history: a PO accumulates one draft
 * per delivery over its life, and the receipt each produced is reachable through
 * `receiving_id`.
 *
 * `zone_label` sits on the header, not the item, because a delivery is put away
 * in one zone. Every item inherits it when the row is posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receiving_drafts')) {
            return;
        }

        Schema::create('receiving_drafts', function (Blueprint $table) {
            $table->bigIncrements('draft_id');
            $table->unsignedBigInteger('po_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('receiving_id')->nullable();
            $table->enum('status', ['open', 'submitted', 'discarded'])->default('open');
            $table->string('zone_label', 100)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            // The hot query is "is there an open draft for this PO at my
            // warehouse?", run every time a clerk enters receive-po mode.
            $table->index(['po_id', 'location_id', 'status'], 'receiving_drafts_open_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_drafts');
    }
};
