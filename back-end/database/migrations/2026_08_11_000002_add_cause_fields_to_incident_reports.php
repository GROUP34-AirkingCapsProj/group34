<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cause moves out of the reason and into reportable fields.
 *
 * `theft` and `not_found` used to be separate reasons; they are now one
 * Missing type with a suspected cause, so the cause can be reported on
 * without the reason list growing a row per nuance.
 *
 * All nullable: every existing incident predates these columns, and only one
 * type populates each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            // Missing
            $table->string('last_known_location', 150)->nullable()->after('actual_zone');
            $table->string('suspected_cause', 20)->nullable()->after('last_known_location');
            // Defective
            $table->string('root_cause', 20)->nullable()->after('suspected_cause');
            // Return
            $table->string('return_direction', 20)->nullable()->after('root_cause');
            $table->string('return_reason', 150)->nullable()->after('return_direction');
            $table->unsignedBigInteger('related_transfer_id')->nullable()->after('related_issuance_id');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table) {
            $table->dropColumn([
                'last_known_location', 'suspected_cause', 'root_cause',
                'return_direction', 'return_reason', 'related_transfer_id',
            ]);
        });
    }
};
