<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch never recorded what its stock cost, so every valuation downstream had
 * to guess by walking receiving_id -> receiving_details. This column is what
 * makes FIFO costing exact instead of plausible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->string('cost_basis', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'cost_basis']);
        });
    }
};
