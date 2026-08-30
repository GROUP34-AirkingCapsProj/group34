<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records, on the issued line itself, why a picker was allowed to deviate from
 * strict FIFO.
 *
 * The override is already captured by the audit trail, so why a column? Because
 * the audit trail stores its payload as a JSON blob: excellent for forensics on
 * one known incident, painful for the question an auditor actually asks —
 * "show me every FIFO override last month, with the reason and the order". That
 * report is a trivial WHERE on a real column and a JSON-extract scan over the
 * whole audit table otherwise. The reason belongs next to the line it explains.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('issuance_details', 'fifo_override_reason')) {
            return;
        }

        Schema::table('issuance_details', function (Blueprint $table) {
            $table->string('fifo_override_reason', 255)->nullable()->after('zone_label');
        });
    }

    public function down(): void
    {
        Schema::table('issuance_details', function (Blueprint $table) {
            $table->dropColumn('fifo_override_reason');
        });
    }
};
