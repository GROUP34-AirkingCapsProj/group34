<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds a seventh AC type: Ceiling Mounted.
     *
     * A ceiling-mounted (ceiling-suspended / under-ceiling) unit is a distinct
     * form factor from a ceiling cassette — it hangs below the slab rather than
     * recessing into it — and 13 units in the live catalogue carry it in
     * appliance_details.variant. The six types seeded by
     * 2026_08_25_000001_create_ac_type_lookup_table have no home for them, so
     * without this row they would all be forced into Split Type.
     *
     * Inserted idempotently rather than by editing migration 000001, which has
     * already run everywhere.
     */
    public function up(): void
    {
        if (DB::table('ac_type_lookup')->where('ac_type_name', 'Ceiling Mounted')->exists()) {
            return;
        }

        $now = now();

        DB::table('ac_type_lookup')->insert([
            // Two-piece: a ceiling-mounted indoor unit pairs with an outdoor
            // condenser.
            'ac_type_name' => 'Ceiling Mounted',
            'is_two_piece' => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /**
     * Removes the row only while nothing points at it. Once appliance_details
     * rows carry the id, deleting it would orphan those FKs to undo a lookup
     * addition — so the row stays and the rollback is a no-op.
     */
    public function down(): void
    {
        $id = DB::table('ac_type_lookup')
            ->where('ac_type_name', 'Ceiling Mounted')
            ->value('ac_type_id');

        if ($id === null) {
            return;
        }

        if (DB::table('appliance_details')->where('ac_type_id', $id)->exists()) {
            return;
        }

        DB::table('ac_type_lookup')->where('ac_type_id', $id)->delete();
    }
};
