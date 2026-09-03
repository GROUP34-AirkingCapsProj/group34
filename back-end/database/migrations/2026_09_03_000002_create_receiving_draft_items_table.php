<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One staged scan or one staged count, waiting to be posted.
 *
 * These columns are a deliberate one-for-one mirror of the parameters
 * ReceivingPostingService::post() already takes. That is the whole design:
 * submitting a draft is a loop that reads a row and spreads it into the
 * existing call, with no translation layer in between to get wrong. Adding a
 * column here that post() does not accept means the mirror has broken and the
 * submit path has grown logic it was never supposed to have.
 *
 * quantity_amount is pieces, matching receiving_details — never packages.
 * packages_received and pieces_expected stay NULL for appliance lines and for
 * piece-counted materials, which have no package to reason about, exactly as
 * they do on receiving_details.
 *
 * contents_verified is a plain boolean here rather than the timestamp used on
 * receiving_details, because a staged row has not been verified AT any moment
 * yet — it only carries the clerk's claim forward. post() converts it to the
 * timestamp when the row is finally posted, so the receipt still records when
 * verification actually took effect.
 *
 * No unique index on scanned_barcode. MySQL has no partial index, so "unique
 * among open drafts only" is not expressible, and a plain unique key would
 * wrongly refuse to re-receive a code whose draft was discarded. Duplicates are
 * caught by ReceivingDraftStagingService at stage time for fast feedback, and
 * the global item_serial.serial_number unique key is the real guarantee at
 * submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receiving_draft_items')) {
            return;
        }

        Schema::create('receiving_draft_items', function (Blueprint $table) {
            $table->bigIncrements('draft_item_id');
            $table->unsignedBigInteger('draft_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('po_detail_id')->nullable();
            $table->enum('entry_mode', ['scanned', 'counted'])->default('scanned');
            $table->string('scanned_barcode', 191)->nullable();
            $table->integer('quantity_amount')->default(0);
            $table->integer('packages_received')->nullable();
            $table->integer('pieces_expected')->nullable();
            $table->boolean('contents_verified')->default(false);
            $table->integer('units_entered')->default(1);
            $table->timestamps();

            $table->index('draft_id');
            $table->index('scanned_barcode');

            // A draft's items are meaningless without their header, so they go
            // with it. Discarding a draft is a real delete, not a tombstone —
            // nothing downstream has ever read these rows, because nothing
            // downstream sees a draft until it becomes receiving_details.
            $table->foreign('draft_id')
                  ->references('draft_id')->on('receiving_drafts')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_draft_items');
    }
};
