<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;

/**
 * A free-form observation. Reviewing it closes the record and nothing else —
 * no adjustment, no correction, no stock movement.
 */
class AcknowledgeResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard — there is no artifact to get wrong.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        return ResolutionArtifacts::none();
    }
}
