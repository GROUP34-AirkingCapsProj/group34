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
     * Guards on the incident, called TWICE: once as a cheap pre-flight before the
     * transaction opens, then again under the row lock as the authority. Must be
     * side-effect-free and must not depend on database state — that is what makes
     * running it twice safe.
     *
     * @throws \InvalidArgumentException when the incident cannot be confirmed
     */
    public function validate(IncidentReport $incident, array $options): void;

    /**
     * Post the artifact. Runs INSIDE IncidentService's transaction, with the
     * incident row already locked and confirmed Open.
     */
    public function apply(IncidentReport $incident, array $options, int $reviewedBy): ResolutionArtifacts;
}
