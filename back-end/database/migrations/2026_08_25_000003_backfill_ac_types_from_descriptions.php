<?php

use App\Support\AcTypeBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AcTypeBackfill::run();
    }

    /**
     * Deliberately does nothing.
     *
     * This migration cannot distinguish an ac_type_id it inferred from a
     * description from one a user has since set by hand, nor a unit_role of
     * 'complete' it wrote from one a user chose. Blanking both columns on
     * rollback would therefore discard real work to undo a guess.
     *
     * The columns themselves are dropped by
     * 2026_08_25_000002_add_ac_fields_to_appliance_details's down(), which is
     * the honest way to reverse this change.
     */
    public function down(): void
    {
    }
};
