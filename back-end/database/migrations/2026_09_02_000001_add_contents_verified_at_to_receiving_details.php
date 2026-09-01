<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a human confirmed what was actually inside the packages.
 *
 * A barcode proves a carton arrived; it says nothing about what was in it. So
 * a SCANNED row's contents are TRUSTED, not verified — it starts NULL and stays
 * NULL until someone opens the boxes and reconciles the line through
 * receive-verify, which stamps every row of that line.
 *
 * A COUNTED row is stamped at entry, because counting IS the verification:
 * the clerk typed the number of pieces they physically had in front of them.
 * The same goes for a loose-piece top-up.
 *
 * EXISTING ROWS ARE BACKFILLED, not left NULL. A row written before this column
 * existed describes a delivery that was put away weeks ago: nobody can open
 * those boxes now, so "unverified" would be a complaint with no action that
 * could ever clear it, and every historical line would nag forever. They are
 * stamped with their own created_at, which states the old system's rule
 * honestly — before this column, whatever was posted WAS the accepted truth.
 *
 * Grandfathering in the DATA rather than at read time is deliberate. The
 * alternative — a cutoff timestamp every reader compares created_at against —
 * puts a clock-skew bug in front of every query (the app runs in UTC; a cutoff
 * a few hours ahead silently grandfathers live rows). After this backfill NULL
 * has exactly one meaning everywhere: a carton that has not been opened yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('receiving_details', 'contents_verified_at')) {
            return;
        }

        Schema::table('receiving_details', function (Blueprint $table) {
            $table->timestamp('contents_verified_at')->nullable()->after('pieces_expected');
        });

        // Grandfather every row that already existed. COALESCE guards rows with
        // no created_at; they are just as unverifiable, and NULL must not
        // survive this statement or they nag forever.
        DB::table('receiving_details')
            ->whereNull('contents_verified_at')
            ->update(['contents_verified_at' => DB::raw('COALESCE(created_at, NOW())')]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('receiving_details', 'contents_verified_at')) {
            return;
        }

        Schema::table('receiving_details', function (Blueprint $table) {
            $table->dropColumn('contents_verified_at');
        });
    }
};
