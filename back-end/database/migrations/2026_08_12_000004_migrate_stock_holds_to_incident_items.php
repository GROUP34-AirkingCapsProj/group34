<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * stock_holds becomes incident_items.
 *
 * Condition maps onto the new status vocabulary; a hold that was still `held`
 * has not been inspected yet, so it becomes `quarantined` under the new order.
 * A released hold already had its decision, which becomes the item's final
 * status.
 *
 * The table is dropped rather than left behind: two tables meaning the same
 * thing, one of them stale, is how a stock count starts disagreeing with the
 * system.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_holds')) {
            return;
        }

        foreach (DB::table('stock_holds')->get() as $hold) {
            DB::table('incident_items')->insert([
                'incident_id'   => $hold->incident_id,
                'product_id'    => $hold->product_id,
                'serial_id'     => $hold->serial_id,
                'batch_id'      => $hold->batch_id,
                'unit_role'     => 'single',
                'quantity'      => $hold->quantity,
                'status'        => $this->statusFor($hold),
                'outcome'       => $this->outcomeFor($hold),
                'outcome_note'  => $hold->disposition_note,
                'created_at'    => $hold->created_at,
                'updated_at'    => $hold->updated_at,
            ]);
        }

        Schema::dropIfExists('stock_holds');
    }

    private function statusFor(object $hold): string
    {
        if ($hold->status === 'held') {
            // Never inspected under the old order either — it was waiting for a
            // disposition, which is what quarantined now means.
            return 'quarantined';
        }

        return match ($hold->disposition) {
            'restocked'            => 'available',
            'scrapped'             => 'scrapped',
            'returned_to_supplier' => 'returned',
            default                => $hold->condition === 'missing' ? 'missing' : 'quarantined',
        };
    }

    private function outcomeFor(object $hold): ?string
    {
        return match ($hold->disposition) {
            'restocked'            => 'restock',
            'scrapped'             => 'scrap',
            'returned_to_supplier' => 'return_to_supplier',
            'defective'            => null,
            default                => $hold->condition === 'missing' ? 'write_off' : null,
        };
    }

    public function down(): void
    {
        // Deliberately irreversible. Rebuilding stock_holds from incident_items
        // would invent disposition values the new vocabulary does not carry, and
        // a wrong reversal here silently corrupts stock counts. Restore from a
        // backup instead.
    }
};
