<?php

use Database\Seeders\LookupBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        LookupBackfill::run();
    }

    /**
     * Deliberately empty. Rolling back would delete lookup rows an admin may
     * since have edited (renamed, re-categorized) by hand, and the tables
     * themselves are dropped by the migrations that created them
     * (2026_07_30_000001/000002/000003), so there is nothing here that a
     * rollback of this migration alone could safely undo.
     */
    public function down(): void
    {
        //
    }
};
