<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an incident's evidence photo arrived: taken through the app's camera, or
 * uploaded from the device.
 *
 * Nullable and deliberately NOT backfilled — every existing row predates the
 * camera, and no backfill could honestly guess. Null reads as "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incident_reports', 'photo_source')) {
            Schema::table('incident_reports', function (Blueprint $table) {
                $table->string('photo_source', 20)->nullable()->after('photo_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('incident_reports', 'photo_source')) {
            Schema::table('incident_reports', function (Blueprint $table) {
                $table->dropColumn('photo_source');
            });
        }
    }
};
