<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both columns are nullable because appliance_details also holds
     * televisions, refrigerators, washing machines and water dispensers,
     * none of which have an AC type or an indoor/outdoor side.
     *
     * unit_role lives here and ONLY here — never on aircon_model_units.
     * A unit line points at a product and the product already knows which
     * side it is; storing the role in both places would give two sources of
     * truth that can disagree. It also means a lone outdoor unit sitting in
     * inventory with no model still reads correctly.
     *
     * unit_role is a plain string, not a DB enum, following the precedent
     * set by 2026_08_13_000002_widen_incident_item_status: that migration's
     * docblock recounts hitting "Data too long" mid-transaction on an enum
     * column and having to widen it, and credits plain strings with making
     * that kind of fix a one-line MODIFY instead of a schema migration. The
     * vocabulary is validated in the controller (see products.product_type
     * for the same pattern), so adding a fourth role later is a one-line
     * validation change, not a migration that must ship before any code can
     * use the new value.
     */
    public function up(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->unsignedBigInteger('ac_type_id')->nullable()->after('variant');
            $table->string('unit_role', 20)
                ->nullable()
                ->after('ac_type_id')
                ->comment('indoor | outdoor | complete');

            $table->foreign('ac_type_id')
                ->references('ac_type_id')
                ->on('ac_type_lookup')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appliance_details', function (Blueprint $table) {
            $table->dropForeign(['ac_type_id']);
            $table->dropColumn(['ac_type_id', 'unit_role']);
        });
    }
};
