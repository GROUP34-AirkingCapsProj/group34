<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only movement ledger the Audit & Evidence Explorer reads.
 *
 * Deliberately declares NO foreign keys. inventory_batch_movements cascade-deletes
 * with its batch, which makes it useless as evidence; an audit row has to outlive
 * the batch, receiving, location and user it describes. Referential integrity is
 * enforced by LedgerPoster, which is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger', function (Blueprint $table) {
            $table->bigIncrements('ledger_id');

            // occurred_at is when stock moved; posted_at is when we recorded it.
            // A gap between them is the backdating signal the Explorer flags.
            //
            // Both carry useCurrent() so the two engines agree. MySQL/MariaDB hand an
            // implicit CURRENT_TIMESTAMP default to the *first* TIMESTAMP column only and
            // a zero-date default to the rest, which NO_ZERO_DATE then rejects outright;
            // Postgres gives neither column a default. Stating it makes the schema
            // identical on both, and "now" is the honest default for either field.
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('posted_at')->useCurrent();

            $table->string('direction', 10);        // in | out | neutral
            $table->string('movement_type', 40);
            $table->string('lens', 10)->default('inventory');

            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('serial_number', 191)->nullable();

            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->decimal('extended_value', 16, 2)->nullable();
            $table->string('cost_basis', 24)->default('actual');

            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();

            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('correlation_id', 64);

            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('reverses_ledger_id')->nullable();
            $table->string('origin', 16)->default('posted');

            // Deterministic idempotency key: a retried request, a double-clicked
            // button and a re-run backfill all collapse onto the same row.
            $table->string('posting_key', 191)->unique();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['product_id', 'occurred_at']);
            $table->index(['movement_type', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index('correlation_id');
            $table->index(['actor_user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger');
    }
};
