<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\StockHold;
use App\Services\InventoryBatchService;
use Illuminate\Support\Facades\DB;

/**
 * The SOLE mutator of stock_holds.
 *
 * Creating a hold and deducting active stock must happen together or the
 * invariant `physical = active + held` breaks silently — a discrepancy nobody
 * notices until a stock count months later. Same discipline as BudgetService:
 * one class owns the table, everything else goes through it.
 */
class StockHoldService
{
    /**
     * Move quantity out of active stock and into a held condition.
     *
     * Clamped to what the batch (or the location, with no batch) actually
     * holds — holding more than exists would manufacture units that no batch
     * ever lost, which is the bug the write-off path had to fix twice.
     *
     * No actor argument: a hold is only ever created by resolving an incident,
     * so who put the stock here is `incident.reviewed_by` and who reported it
     * is `incident.reported_by`. A `held_by` column would duplicate one of
     * them and could disagree with it.
     */
    public function hold(IncidentReport $incident, string $condition, int $quantity): StockHold
    {
        if (! in_array($condition, StockHold::CONDITIONS, true)) {
            throw new \InvalidArgumentException("Unknown hold condition: {$condition}");
        }

        return DB::transaction(function () use ($incident, $condition, $quantity) {
            $batch = $incident->batch_id !== null
                ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
                : null;

            $inventory = Inventory::where('location_id', $incident->location_id)
                ->where('product_id', $incident->product_id)
                ->lockForUpdate()
                ->first();

            $available = $batch !== null
                ? (int) $batch->quantity_remaining
                : (int) ($inventory->quantity_on_hand ?? $quantity);

            $moved = max(0, min($quantity, $available));

            if ($batch !== null) {
                $batch->decrement('quantity_remaining', $moved);
            }

            if ($inventory !== null) {
                $inventory->decrement('quantity_on_hand', min($moved, (int) $inventory->quantity_on_hand));
                $inventory->decrement('available_quantity', min($moved, (int) $inventory->available_quantity));
            }

            $this->stampSerial($incident->serial_id, StockHold::SERIAL_STATUS_NAME[$condition]);

            return StockHold::create([
                'incident_id' => (int) $incident->incident_id,
                'product_id'  => (int) $incident->product_id,
                'location_id' => (int) $incident->location_id,
                'batch_id'    => $incident->batch_id,
                'serial_id'   => $incident->serial_id,
                'condition'   => $condition,
                'quantity'    => $moved,
                'status'      => StockHold::STATUS_HELD,
            ]);
        });
    }

    /**
     * Close a hold and decide where the stock went.
     *
     * Only `restocked` returns quantity to active stock. Everything else ends
     * the hold — the units left the building, or were destroyed, or moved to a
     * fresh defective hold.
     */
    public function dispose(StockHold $hold, string $disposition, ?string $note, int $actorId): StockHold
    {
        $allowed = StockHold::ALLOWED_DISPOSITIONS[$hold->condition] ?? [];

        if (! in_array($disposition, $allowed, true)) {
            throw new \InvalidArgumentException(
                "A '{$hold->condition}' hold cannot be disposed as '{$disposition}'."
            );
        }

        return DB::transaction(function () use ($hold, $disposition, $note, $actorId) {
            // Re-read under the lock: two inspectors disposing at once must not
            // both pass the status guard and restock the quantity twice.
            $locked = StockHold::whereKey($hold->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== StockHold::STATUS_HELD) {
                throw new \RuntimeException('This hold has already been disposed.');
            }

            if ($disposition === StockHold::DISPOSITION_RESTOCKED) {
                $this->returnToStock($locked);
                $this->stampSerial($locked->serial_id, 'In Stock', makeReceivable: true);
            } elseif ($disposition === StockHold::DISPOSITION_DEFECTIVE) {
                $this->stampSerial($locked->serial_id, 'Defective');
            }

            $locked->update([
                'status'           => StockHold::STATUS_RELEASED,
                'disposition'      => $disposition,
                'disposition_note' => $note,
                'disposed_by'      => $actorId,
                'disposed_at'      => now(),
            ]);

            // A returned unit found to be defective stays held — it is still in
            // the building and still not sellable, just for a different reason.
            if ($disposition === StockHold::DISPOSITION_DEFECTIVE) {
                StockHold::create([
                    'incident_id' => (int) $locked->incident_id,
                    'product_id'  => (int) $locked->product_id,
                    'location_id' => (int) $locked->location_id,
                    'batch_id'    => $locked->batch_id,
                    'serial_id'   => $locked->serial_id,
                    'condition'   => StockHold::CONDITION_DEFECTIVE,
                    'quantity'    => (int) $locked->quantity,
                    'status'      => StockHold::STATUS_HELD,
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Put a restocked quantity back where it came from.
     *
     * The batchless case is not optional. Raising quantity_on_hand without a
     * batch row leaves stock the location total can see but FIFO picking can
     * never reach — exactly the batch/location divergence the write-off path
     * has a test guarding against. Units with no named batch go to the
     * location's legacy batch, whose null received_at sorts them first: goods
     * of unknown provenance get picked before traceable stock, which is what
     * you want for a return.
     */
    private function returnToStock(StockHold $hold): void
    {
        $quantity = (int) $hold->quantity;

        if ($hold->batch_id !== null) {
            // Named batch: locked before the location total, the same order the
            // deduction path takes, so a hold and a restock racing on one batch
            // queue up rather than deadlocking.
            $batch = InventoryBatch::lockForUpdate()->find($hold->batch_id);
            $batch?->increment('quantity_remaining', $quantity);
            $this->restoreInventory($hold, $quantity);

            return;
        }

        // No batch named, so the order INVERTS on purpose: InventoryBatchService
        // requires the Inventory row to be locked before legacyBatch() is
        // called, because its firstOrCreate has no lock of its own and relies on
        // that row to serialize — two unserialized callers would each create a
        // "single" catch-all batch. deductFifo's spillover takes the same
        // inventory-then-legacy order, so the two cannot cycle.
        $this->restoreInventory($hold, $quantity);

        (new InventoryBatchService())
            ->legacyBatch((int) $hold->product_id, (int) $hold->location_id)
            ->increment('quantity_remaining', $quantity);
        // quantity_received stays 0: it is meaningless for a catch-all that
        // never corresponded to a receiving, and deductFifo already treats it
        // that way.
    }

    private function restoreInventory(StockHold $hold, int $quantity): void
    {
        $inventory = Inventory::where('location_id', $hold->location_id)
            ->where('product_id', $hold->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            // A product with no stock row at this location — the restock is the
            // first thing that ever put it there. Insert it already holding the
            // units rather than creating a zero row and incrementing it.
            Inventory::create([
                'location_id'        => (int) $hold->location_id,
                'product_id'         => (int) $hold->product_id,
                'quantity_on_hand'   => $quantity,
                'available_quantity' => $quantity,
                'reorder_level'      => 0,
                'status_id'          => StockHold::inventoryStatusId('In Stock'),
            ]);

            return;
        }

        $inventory->increment('quantity_on_hand', $quantity);
        $inventory->increment('available_quantity', $quantity);
    }

    /**
     * Keep the serial's own status honest, so a scan never lies about a held unit.
     *
     * `$makeReceivable` also resets serial_type to 'received'. That pair has to
     * move together: scopeIssuableAt and FifoPickingService::serialSteps both
     * require serial_type 'received', so a serial flipped back to In Stock while
     * still typed 'issued' or 'transferred' reads as in stock everywhere and is
     * pickable nowhere.
     */
    private function stampSerial(?int $serialId, string $statusName, bool $makeReceivable = false): void
    {
        if ($serialId === null) {
            return;
        }

        $columns = ['status_id' => StockHold::inventoryStatusId($statusName)];

        if ($makeReceivable) {
            $columns['serial_type'] = 'received';
        }

        ItemSerial::where('serial_id', $serialId)->update($columns);
    }
}
