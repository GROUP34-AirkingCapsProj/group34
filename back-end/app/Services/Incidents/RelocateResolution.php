<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;

/**
 * A zone mismatch is a REPORT, not a correction.
 *
 * The reporter sees serial 1234 physically in zone 2 while the system says
 * zone 1 — and the system is right. Someone misplaced the box. The fix is to
 * walk over and move it back, so resolving must change nothing at all: not the
 * batch's zone_label, not the quantity, not a hold.
 *
 * An earlier version of this class rewrote zone_label from actual_zone, which
 * overwrote a correct record with the wrong physical location. The incident's
 * expected_zone and actual_zone columns are the whole artifact.
 */
class RelocateResolution implements ResolvesIncident
{
    public function validate(IncidentReport $incident, array $options): void
    {
        // Nothing to guard: recording an observation cannot fail.
    }

    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts
    {
        return ResolutionArtifacts::none();
    }
}
