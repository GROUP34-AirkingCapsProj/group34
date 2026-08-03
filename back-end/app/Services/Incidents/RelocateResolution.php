<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;
use App\Models\InventoryBatch;

/** Stock exists, it is simply somewhere else. No quantity moves. */
class RelocateResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // A relocation with no batch or no actual zone is a no-op, not an error —
        // preserving the pre-refactor behaviour.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): array
    {
        if ($incident->batch_id !== null && $incident->actual_zone !== null) {
            InventoryBatch::where('batch_id', $incident->batch_id)
                ->update(['zone_label' => $incident->actual_zone]);
        }

        return ['adjustment_id' => null, 'correction_id' => null];
    }
}
