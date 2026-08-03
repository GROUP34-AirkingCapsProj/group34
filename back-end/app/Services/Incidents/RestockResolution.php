<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Services\InventoryBatchService;

/**
 * The customer sent it back. This is the only resolution that ADDS stock.
 *
 * Returned units go back onto the batch they left, so the batch keeps its true
 * received_at and its place in FIFO order — a return is not new stock. When the
 * incident names no batch (the original was deleted, or the return was filed
 * without one) the units go to the location's legacy batch, where a null
 * received_at sorts them first: goods of unknown provenance get picked before
 * traceable stock, which is what you want for a return.
 *
 * Stock comes back available and is not quarantined. If a return arrives
 * damaged, that is a second incident with reason `damaged`.
 */
class RestockResolution implements ResolvesIncident
{
    public function __construct(
        private readonly InventoryBatchService $batches = new InventoryBatchService(),
    ) {
    }

    public function validate(IncidentReport $incident, array $options): void
    {
        // Only a lower bound. Deliberately no upper clamp: WriteOffResolution
        // caps its deduction at what the batch actually holds, but a return has
        // no equivalent ceiling — a standalone return has no issuance to check
        // the count against, so clamping would invent an authority this class
        // does not have. The reviewer approving the incident is the bound.
        if ((int) $incident->quantity < 1) {
            throw new \InvalidArgumentException('A customer return must specify how many units came back.');
        }
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $quantity = (int) $incident->quantity;

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-RET-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => 'return',
            'total_variance_positive' => $quantity,
            'total_variance_negative' => 0,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => $quantity,
            'deduct_quantity' => 0,
            'reason'          => $incident->reason,
        ]);

        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        if ($batch !== null) {
            // Named batch: locked before the location total, the order
            // WriteOffResolution takes, so a write-off and a restock racing on
            // the same batch queue up rather than deadlocking.
            $batch->increment('quantity_remaining', $quantity);
            $this->restoreInventory($incident, $quantity);
        } else {
            // No batch named, so the units go to the legacy batch. The order
            // inverts here on purpose: InventoryBatchService's contract requires
            // the Inventory row to be locked *before* legacyBatch() is called,
            // because its firstOrCreate has no lock of its own and relies on
            // that row to serialize — two unserialized callers would each
            // create a "single" catch-all batch. deductFifo's spillover takes
            // the same inventory-then-legacy order, so the two cannot cycle.
            $this->restoreInventory($incident, $quantity);
            $this->batches
                ->legacyBatch((int) $incident->product_id, (int) $incident->location_id)
                ->increment('quantity_remaining', $quantity);
            // quantity_received is left at 0: it is meaningless for a catch-all
            // that never corresponded to a receiving, and deductFifo's spillover
            // already treats it that way.
        }

        if ($incident->serial_id !== null) {
            // serial_type matters as much as the status: scopeIssuableAt and
            // FifoPickingService::serialSteps both require 'received', so a
            // serial flipped to In Stock while still typed 'transferred' or
            // 'issued' would be unpickable. WarrantyClaimService sets the pair
            // together for the same reason.
            ItemSerial::where('serial_id', $incident->serial_id)
                ->update([
                    'serial_type' => 'received',
                    'status_id'   => ItemSerial::IN_STOCK_STATUS_ID,
                ]);
        }

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }

    private function restoreInventory(IncidentReport $incident, int $quantity): void
    {
        $inventory = Inventory::where('location_id', $incident->location_id)
            ->where('product_id', $incident->product_id)
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            // A product with no stock row at this location — the return is the
            // first thing that ever put it there. Insert it already holding the
            // units rather than creating a zero row and incrementing it.
            Inventory::create([
                'location_id'        => (int) $incident->location_id,
                'product_id'         => (int) $incident->product_id,
                'quantity_on_hand'   => $quantity,
                'available_quantity' => $quantity,
                'reorder_level'      => 0,
            ]);

            return;
        }

        $inventory->increment('quantity_on_hand', $quantity);
        $inventory->increment('available_quantity', $quantity);
    }
}
