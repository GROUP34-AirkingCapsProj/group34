<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings raised by the drift checks that reconcile the ledger against its
 * sources, the FIFO movement rows and live stock.
 *
 * Like inventory_ledger, declares NO foreign keys: a finding records what was
 * true when the check ran, so it has to survive the product, location or user it
 * points at being deleted. Losing the finding would hide the discrepancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_drift_findings', function (Blueprint $table) {
            $table->bigIncrements('finding_id');
            $table->string('check_type', 40);      // source_vs_ledger | ledger_vs_movements | ledger_vs_stock | check_failed
            $table->string('source_type', 40)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            // jsonb, not json: PostgreSQL's `json` type has no equality operator,
            // and DriftChecker matches an existing finding with
            // `where('scope', json_encode($scope))`. On `json` that is
            // "operator does not exist: json = unknown" at runtime; jsonb compares
            // semantically, so key order stops mattering too. MySQL maps both
            // spellings to `json`, so this changes nothing there.
            $table->jsonb('scope')->nullable();    // {product_id, location_id} where applicable
            $table->decimal('expected', 18, 2)->nullable();
            $table->decimal('actual', 18, 2)->nullable();
            $table->decimal('variance', 18, 2)->nullable();
            $table->string('detail', 500)->nullable();
            // useCurrent() is load-bearing, not decoration. Left bare, this is the
            // first TIMESTAMP column in the table, so MySQL/MariaDB attach
            // ON UPDATE CURRENT_TIMESTAMP to it -- and then the very act of
            // resolving a finding (writing resolved_at/resolved_by) would rewrite
            // detected_at to now and destroy the detection time being audited.
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['check_type', 'detected_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_drift_findings');
    }
};
