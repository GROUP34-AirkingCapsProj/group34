<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;

/**
 * The customer sent it back. This is the only resolution that ADDS stock.
 *
 * Returned units go back onto the batch they left, so the batch keeps its true
 * received_at and its place in FIFO order — a return is not new stock. Only
 * when the incident names no batch (the original was deleted, or the return
 * was filed without one) does a fresh batch get created.
 *
 * Stock comes back available and is not quarantined. If a return arrives
 * damaged, that is a second incident with reason `damaged`.
 */
class RestockResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
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

        // Batch first, then the location total — the same lock order
        // WriteOffResolution takes, so a write-off and a restock racing on the
        // same product queue up instead of deadlocking.
        $this->restoreBatch($incident, $quantity);
        $this->restoreInventory($incident, $quantity);

        if ($incident->serial_id !== null) {
            ItemSerial::where('serial_id', $incident->serial_id)
                ->update(['status_id' => ItemSerial::IN_STOCK_STATUS_ID]);
        }

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }

    private function restoreBatch(IncidentReport $incident, int $quantity): void
    {
        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        if ($batch !== null) {
            $batch->increment('quantity_remaining', $quantity);
            return;
        }

        // No batch to return to. receiving_id stays null because a return did not
        // come from a receiving — FifoPickingService already handles batchless
        // stock, and the column is nullable.
        InventoryBatch::create([
            'product_id'         => (int) $incident->product_id,
            'location_id'        => (int) $incident->location_id,
            'receiving_id'       => null,
            'quantity_received'  => $quantity,
            'quantity_remaining' => $quantity,
            'received_at'        => now(),
            'zone_label'         => null,
        ]);
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
