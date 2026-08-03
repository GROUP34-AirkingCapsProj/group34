<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opens incident_reports up to standalone reporting.
 *
 * source_id and product_id become nullable because an incident is no longer
 * necessarily discovered during a pick: a standalone report has no source
 * transaction, and an `other` incident ("the cold-room door lock is broken")
 * has no product. quantity deliberately stays NOT NULL DEFAULT 0 — an `other`
 * incident stores 0, and a nullable quantity would buy a distinction nothing
 * consumes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // product_id carries a foreign key, so MySQL needs it dropped before the
        // column's nullability can change, then re-added afterwards.
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('incident_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('source_id')->nullable()->change();
            $table->unsignedBigInteger('product_id')->nullable()->change();

            if (! Schema::hasColumn('incident_reports', 'occurred_at')) {
                // When it actually happened, as opposed to created_at (when it was
                // filed). A theft noticed the next morning has two different dates.
                $table->dateTime('occurred_at')->nullable()->after('narrative');
            }

            if (! Schema::hasColumn('incident_reports', 'related_issuance_id')) {
                // The sale a customer_return came from. Deliberately NOT source_id:
                // source_type/source_id mean "discovered while picking X", this
                // means "this incident is about sale Y".
                $table->unsignedBigInteger('related_issuance_id')->nullable()->after('source_id');
            }
        });

        Schema::table('incident_reports', function (Blueprint $table) {
            $table->foreign('product_id')->references('product_id')->on('products')->restrictOnDelete();
            $table->foreign('related_issuance_id')->references('issuance_id')->on('issuances')->nullOnDelete();
            $table->index('related_issuance_id', 'incident_related_issuance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropForeign(['related_issuance_id']);
            $table->dropIndex('incident_related_issuance_idx');
            $table->dropColumn(['occurred_at', 'related_issuance_id']);
        });
    }
};
