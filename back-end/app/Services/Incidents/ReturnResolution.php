<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;
use App\Models\StockHold;
use App\Services\StockHoldService;

/**
 * Stock coming back — from a customer, or on its way back to a supplier.
 *
 * REVERSAL: RestockResolution used to increment the batch and the location on
 * approval, making a customer return immediately pickable with nobody having
 * inspected it. It now opens a `returned` hold instead; the increment happens
 * on a `restocked` disposition, when a person says the unit is sellable.
 *
 * No Adjustment is posted: the stock has not been lost or damaged, it is
 * simply not sellable yet. The hold row is the record.
 */
class ReturnResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: a return is valid with or without a batch, and the
        // batch only matters if it is later restocked.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        app(StockHoldService::class)
            ->hold($incident, StockHold::CONDITION_RETURNED, (int) $incident->quantity);

        return ResolutionArtifacts::none();
    }
}
