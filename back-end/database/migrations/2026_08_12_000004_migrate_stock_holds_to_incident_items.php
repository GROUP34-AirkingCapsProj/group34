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

        // Which reports are already finished, by name — the incident status ids
        // are install-specific, so matching on the number would be silently
        // wrong on any database but the one it was written against.
        $closures = DB::table('incident_reports as ir')
            ->join('status_lookup as sl', 'sl.status_id', '=', 'ir.status_id')
            ->where('sl.status_category', 'incident')
            ->whereIn('sl.status_name', ['Resolved', 'Dismissed'])
            ->pluck('sl.status_name', 'ir.incident_id');

        $stranded = 0;

        foreach (DB::table('stock_holds')->get() as $hold) {
            $closedAs = $closures[$hold->incident_id] ?? null;
            $status   = $this->statusFor($hold, $closedAs);

            if ($status === 'quarantined' && $closedAs !== null) {
                $stranded++;
            }

            DB::table('incident_items')->insert([
                'incident_id'   => $hold->incident_id,
                'product_id'    => $hold->product_id,
                'serial_id'     => $hold->serial_id,
                'batch_id'      => $hold->batch_id,
                'unit_role'     => 'single',
                'quantity'      => $hold->quantity,
                'status'        => $status,
                'outcome'       => $this->outcomeFor($hold),
                'outcome_note'  => $hold->disposition_note,
                'created_at'    => $hold->created_at,
                'updated_at'    => $hold->updated_at,
            ]);
        }

        if ($stranded > 0) {
            echo "  migrate_stock_holds_to_incident_items: {$stranded} item(s) under a "
                . "closed report carry no disposition and stay quarantined — they are "
                . "held out of picking until somebody reviews them.\n";
        }

        Schema::dropIfExists('stock_holds');
    }

    /**
     * $closedAs is the parent report's status NAME once it is finished —
     * 'Resolved', 'Dismissed', or null while it is still in flight.
     *
     * It has to be consulted. `quarantined` means "somebody still has to
     * decide", and a closed report has no screen left to decide it on: the
     * batch stays out of FIFO picking and nothing in the UI can ever release
     * it. So a hold under a closed report is settled here the way closing the
     * report would have settled it.
     */
    private function statusFor(object $hold, ?string $closedAs): string
    {
        if ($hold->status === 'held' && $closedAs === null) {
            // Still in flight: not inspected under the old order either — it was
            // waiting for a disposition, which is what quarantined now means.
            return 'quarantined';
        }

        $status = match ($hold->disposition) {
            'restocked'            => 'available',
            'scrapped'             => 'scrapped',
            'returned_to_supplier' => 'returned',
            default                => $hold->condition === 'missing' ? 'missing' : 'quarantined',
        };

        // A dismissal says the report described nothing real, so whatever is
        // still undecided goes back to the picking pool — exactly what
        // IncidentItemService::release() does, and for the same reason it
        // ignores anything an inspector recorded. A hold that already has a
        // disposition keeps it: those goods physically moved.
        if ($status === 'quarantined' && $closedAs === 'Dismissed') {
            return 'available';
        }

        // Resolved with nothing recorded falls through as quarantined on
        // purpose. Guessing at stock is worse than leaving it; up() warns so a
        // human decides instead of the migration.
        return $status;
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
