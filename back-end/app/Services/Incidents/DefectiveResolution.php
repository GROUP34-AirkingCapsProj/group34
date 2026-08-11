<?php

namespace App\Services\Incidents;

use App\Models\Adjustment;
use App\Models\IncidentReport;
use App\Models\StockHold;
use App\Services\StockHoldService;

/**
 * The stock exists but cannot be sold. Deduct it from active inventory and
 * open a `defective` hold, which awaits an inspection deciding whether it goes
 * back to the supplier or is scrapped.
 *
 * `root_cause` (supplier / internal / delivery / other) is carried on the
 * incident, not here — this strategy behaves the same whoever caused it.
 */
class DefectiveResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: damage is valid with or without a batch.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        $hold = app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_DEFECTIVE, (int) $incident->quantity);

        $adjustment = Adjustment::create([
            'location_id'             => (int) $incident->location_id,
            'created_by'              => (int) $incident->reported_by,
            'approved_by'             => $reviewedBy,
            'adjustment_number'       => 'ADJ-INC-' . strtoupper(substr(uniqid(), -6)),
            'adjustment_date'         => now()->toDateString(),
            'adjustment_type'         => 'damage',
            'total_variance_positive' => 0,
            'total_variance_negative' => (int) $hold->quantity,
            'notes'                   => "Incident {$incident->incident_number}: {$incident->narrative}",
            'status_id'               => 20,
        ]);

        $adjustment->details()->create([
            'product_id'      => (int) $incident->product_id,
            'add_quantity'    => 0,
            'deduct_quantity' => (int) $hold->quantity,
            'reason'          => $incident->reason,
        ]);

        return ResolutionArtifacts::adjustment((int) $adjustment->adjustment_id);
    }
}
