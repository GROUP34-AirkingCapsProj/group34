<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Materials that arrived WITH a supplier barcode were given a generated INT-
 * serial alongside it. A generated barcode exists only because an item arrived
 * without one, so those rows adopt the supplier's code as their serial.
 *
 * supplier_barcode is left populated: after the rewrite both columns hold the
 * same value, which is the shape a scanned material now takes.
 *
 * serial_number is globally unique. Where the supplier's code already exists as
 * a serial elsewhere the rewrite would collide, so those rows are left as they
 * are and logged. A handful of stragglers keeping an INT- serial is a far better
 * outcome than a migration that fails halfway through.
 */
return new class extends Migration
{
    public function up(): void
    {
        $candidates = DB::table('item_serial')
            ->where('serial_number', 'like', 'INT-%')
            ->whereNotNull('supplier_barcode')
            ->where('supplier_barcode', '!=', '')
            ->get(['serial_id', 'serial_number', 'supplier_barcode']);

        foreach ($candidates as $row) {
            $taken = DB::table('item_serial')
                ->where('serial_number', $row->supplier_barcode)
                ->where('serial_id', '!=', $row->serial_id)
                ->exists();

            if ($taken) {
                Log::warning('rewrite_scanned_material_tags: skipped colliding serial', [
                    'serial_id'        => $row->serial_id,
                    'kept'             => $row->serial_number,
                    'supplier_barcode' => $row->supplier_barcode,
                ]);
                continue;
            }

            DB::table('item_serial')
                ->where('serial_id', $row->serial_id)
                ->update(['serial_number' => $row->supplier_barcode, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Irreversible by design: the generated INT- serials are not recorded
        // anywhere else, so they cannot be reconstructed. The rows remain valid
        // and fully linked to their batches either way.
    }
};
