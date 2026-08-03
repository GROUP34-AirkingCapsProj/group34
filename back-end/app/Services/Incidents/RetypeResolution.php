<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ScanCorrection;

/**
 * The batch was received as the wrong product. Re-type it to what is
 * physically on the shelf and move the inventory to match.
 */
class RetypeResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        if (empty($options['corrected_product_id'])) {
            throw new \InvalidArgumentException('A corrected product is required to resolve a wrong-type incident.');
        }

        if ($incident->batch_id === null) {
            // With no batch there is nothing to re-type, so closing this would
            // leave the real stock wrong while the record reads as fixed.
            throw new \InvalidArgumentException('A wrong-type incident must reference a batch to re-type.');
        }
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): array
    {
        $correctedProductId = (int) $options['corrected_product_id'];

        // Locked to match WriteOffResolution's discipline — the re-type and the
        // paired inventory move must see and hold a consistent batch row.
        $batch = $incident->batch_id !== null
            ? InventoryBatch::lockForUpdate()->find($incident->batch_id)
            : null;

        $correction = ScanCorrection::create([
            'receiving_id'         => $batch?->receiving_id,
            'original_product_id'  => (int) $incident->product_id,
            'corrected_product_id' => $correctedProductId,
            'correction_type'      => 'wrong_product',
            'quantity_before'      => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'quantity_after'       => $batch !== null ? (int) $batch->quantity_remaining : (int) $incident->quantity,
            'serial_id'            => $incident->serial_id,
            'reason'               => "Incident {$incident->incident_number}: {$incident->narrative}",
            'corrected_by'         => (int) $incident->reported_by,
            'approved_by'          => $reviewedBy,
        ]);

        if ($batch !== null) {
            $quantity = (int) $batch->quantity_remaining;

            $batch->update(['product_id' => $correctedProductId]);

            $from = Inventory::where('location_id', $incident->location_id)
                ->where('product_id', $incident->product_id)->lockForUpdate()->first();
            if ($from !== null) {
                $from->decrement('quantity_on_hand', min($quantity, (int) $from->quantity_on_hand));
                $from->decrement('available_quantity', min($quantity, (int) $from->available_quantity));
            }

            $to = Inventory::firstOrCreate(
                ['location_id' => (int) $incident->location_id, 'product_id' => $correctedProductId],
                ['quantity_on_hand' => 0, 'available_quantity' => 0, 'reorder_level' => 0]
            );
            $to->increment('quantity_on_hand', $quantity);
            $to->increment('available_quantity', $quantity);
        }

        return ['adjustment_id' => null, 'correction_id' => (int) $correction->correction_id];
    }
}
