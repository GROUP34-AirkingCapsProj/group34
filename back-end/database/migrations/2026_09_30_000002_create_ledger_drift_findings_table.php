<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->json('scope')->nullable();     // {product_id, location_id} where applicable
            $table->decimal('expected', 18, 2)->nullable();
            $table->decimal('actual', 18, 2)->nullable();
            $table->decimal('variance', 18, 2)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamp('detected_at');
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
