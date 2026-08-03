<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;

/**
 * Stock is gone. Write it off the batch and the location inventory under an
 * approved Adjustment.
 */
class WriteOffResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: a write-off is valid with or without a batch.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $requested = max(0, (int) $incident->quantity);

        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        $inventory = Inventory::where('location_id', $incident->location_id)
            ->where('product_id', $incident->product_id)
            ->lockForUpdate()
            ->first();

        // The real write-off cannot exceed what the batch actually holds (or, with
        // no batch, what the location holds). ONE deducted amount drives the
        // ledger, the batch, and the location total alike — clamping them
        // independently vanished units that no batch ever lost.
        $available = $batch !== null
            ? (int) $batch->quantity_remaining
            : (int) ($inventory->quantity_on_hand ?? $requested);
        $deducted = min($requested, $available);

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => $this->adjustmentType($incident->reason),
            'total_variance_positive' => 0,
            'total_variance_negative' => $deducted,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20, // adjustment "Approved" — verified in status_lookup
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => $deducted,
            'reason'          => $incident->reason,
        ]);

        if ($batch !== null) {
            $batch->decrement('quantity_remaining', $deducted);
        }

        if ($inventory !== null) {
            $inventory->decrement('quantity_on_hand', min($deducted, (int) $inventory->quantity_on_hand));
            $inventory->decrement('available_quantity', min($deducted, (int) $inventory->available_quantity));
        }

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }

    /**
     * "Loss" means the stock is gone; "damage" means it exists but is unsellable.
     * theft is deliberately its own reason rather than not_found — the
     * distinction is the point for shrinkage reporting — but both post a loss.
     */
    private function adjustmentType(string $reason): string
    {
        return in_array($reason, [IncidentReport::REASON_DAMAGED, IncidentReport::REASON_DELIVERY_DAMAGE], true)
            ? 'damage'
            : 'loss';
    }
}
