<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Recreate receiving batches that predate batch tracking.
        $received = DB::table('receiving_details as d')
            ->join('receivings as r', 'r.receiving_id', '=', 'd.receiving_id')
            ->groupBy('d.product_id', 'r.location_id', 'd.receiving_id', 'r.receiving_date')
            ->selectRaw('d.product_id, r.location_id, d.receiving_id, r.receiving_date, '
                . 'SUM(d.quantity_amount) as quantity_received')
            ->get();

        foreach ($received as $row) {
            $exists = DB::table('inventory_batches')
                ->where('product_id', $row->product_id)
                ->where('location_id', $row->location_id)
                ->where('receiving_id', $row->receiving_id)
                ->exists();

            if (! $exists) {
                DB::table('inventory_batches')->insert([
                    'product_id' => $row->product_id,
                    'location_id' => $row->location_id,
                    'receiving_id' => $row->receiving_id,
                    'quantity_received' => (int) $row->quantity_received,
                    'quantity_remaining' => (int) $row->quantity_received,
                    'received_at' => $row->receiving_date,
                    'zone_label' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('inventory')->get() as $inventory) {
            $batches = DB::table('inventory_batches')
                ->where('product_id', $inventory->product_id)
                ->where('location_id', $inventory->location_id)
                ->orderByRaw('receiving_id IS NULL')
                ->orderBy('received_at')
                ->orderBy('batch_id')
                ->get();

            $target = (int) $inventory->quantity_on_hand;
            $current = (int) $batches->sum('quantity_remaining');

            // Absorb legacy stock into known receiving batches whenever their
            // original capacity can account for it.
            $legacy = $batches->firstWhere('receiving_id', null);
            $legacyRemaining = (int) ($legacy->quantity_remaining ?? 0);
            if ($legacy && $legacyRemaining > 0) {
                foreach ($batches->whereNotNull('receiving_id') as $batch) {
                    if ($legacyRemaining <= 0) break;
                    $room = max(0, (int) $batch->quantity_received - (int) $batch->quantity_remaining);
                    $move = min($legacyRemaining, $room);
                    if ($move > 0) {
                        DB::table('inventory_batches')->where('batch_id', $batch->batch_id)
                            ->update([
                                'quantity_remaining' => (int) $batch->quantity_remaining + $move,
                                'updated_at' => now(),
                            ]);
                        $legacyRemaining -= $move;
                    }
                }
                DB::table('inventory_batches')->where('batch_id', $legacy->batch_id)
                    ->update([
                        'quantity_remaining' => $legacyRemaining,
                        'quantity_received' => max($legacyRemaining, (int) $legacy->quantity_received),
                        'updated_at' => now(),
                    ]);
            }

            // Too much batch stock: consume oldest stock first (FIFO).
            if ($current > $target) {
                $remove = $current - $target;
                foreach ($batches as $batch) {
                    if ($remove <= 0) break;
                    $take = min($remove, (int) $batch->quantity_remaining);
                    DB::table('inventory_batches')->where('batch_id', $batch->batch_id)
                        ->update([
                            'quantity_remaining' => (int) $batch->quantity_remaining - $take,
                            'updated_at' => now(),
                        ]);
                    $remove -= $take;
                }
            }

            // Too little batch stock: first refill traceable FIFO batches up to
            // their received quantity, then retain any true discrepancy as legacy.
            if ($current < $target) {
                $add = $target - $current;
                foreach ($batches->whereNotNull('receiving_id') as $batch) {
                    if ($add <= 0) break;
                    $room = max(0, (int) $batch->quantity_received - (int) $batch->quantity_remaining);
                    $put = min($add, $room);
                    if ($put > 0) {
                        DB::table('inventory_batches')->where('batch_id', $batch->batch_id)
                            ->update([
                                'quantity_remaining' => (int) $batch->quantity_remaining + $put,
                                'updated_at' => now(),
                            ]);
                        $add -= $put;
                    }
                }

                if ($add > 0) {
                    $legacy = DB::table('inventory_batches')
                        ->where('product_id', $inventory->product_id)
                        ->where('location_id', $inventory->location_id)
                        ->whereNull('receiving_id')
                        ->first();
                    if ($legacy) {
                        DB::table('inventory_batches')->where('batch_id', $legacy->batch_id)
                            ->update([
                                'quantity_received' => max(
                                    (int) $legacy->quantity_received,
                                    (int) $legacy->quantity_remaining + $add
                                ),
                                'quantity_remaining' => (int) $legacy->quantity_remaining + $add,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('inventory_batches')->insert([
                            'product_id' => $inventory->product_id,
                            'location_id' => $inventory->location_id,
                            'receiving_id' => null,
                            'quantity_received' => $add,
                            'quantity_remaining' => $add,
                            'received_at' => null,
                            'zone_label' => 'Untracked opening stock',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Data reconciliation is intentionally not reversed.
    }
};
