<?php

namespace App\Services\Incidents;

use App\Models\IncidentReport;

/**
 * One resolution outcome for one family of incident reasons.
 *
 * IncidentService owns the transaction, the row lock, the re-check that the
 * incident is still Open, and the status stamping. A strategy owns only the
 * artifact it posts — so adding a reason means adding a class, not lengthening
 * a switch, and each outcome can be read and tested on its own.
 */
interface ResolvesIncident
{
    /**
     * Guards that don't touch the database — cheap to fail fast, before the
     * transaction opens.
     *
     * @throws \InvalidArgumentException when the incident cannot be confirmed
     */
    public function validate(IncidentReport $incident, array $options): void;

    /**
     * Post the artifact. Runs INSIDE IncidentService's transaction, with the
     * incident row already locked and confirmed Open.
     *
     * @return array{adjustment_id: int|null, correction_id: int|null}
     */
    public function apply(IncidentReport $incident, array $options, int $reviewedBy): array;
}
