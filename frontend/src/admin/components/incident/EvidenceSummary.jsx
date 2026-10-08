import React from 'react';
import { requirementMeta, requirementsFor } from '../../utils/incidentDocument';
import { EvidenceThumbnail, PhotoSourceBadge } from './EvidencePhoto';

/**
 * The evidence checklist as the ADMIN reads it — the same requirements the
 * inspector answered, with no way to add, skip or remove anything.
 *
 * This is the section the whole change exists for: it rendered completely
 * empty, so an admin approving a write-off could not see a single photograph of
 * the unit being written off.
 *
 * IT IS ITS OWN FILE because the editable EvidenceChecklist is its own file.
 * The two are one-for-one twins over the same data — one that writes, one that
 * only reads — and someone changing what a requirement looks like should find
 * both halves sitting side by side rather than one of them folded into the
 * middle of the findings-and-signatures summary.
 *
 * Nothing here imports a mutating API call, `toast`, or the camera. That is the
 * same barrier InspectionSummary keeps, and for the same reason: an admin's
 * rendering should have no write path to reach by accident.
 *
 * `checkedByAsc` must come from the SAVED report rather than any local state.
 * Ticking the ASC box is what adds the service report to the required list, and
 * the admin is reading what was submitted, not composing it.
 */
export function EvidenceSummary({ incidentId, reason, checkedByAsc, returnReasonCode, evidence }) {
  const required = requirementsFor(reason, checkedByAsc, returnReasonCode);
  const rows = Array.isArray(evidence) ? evidence : [];
  const extra = rows.filter((e) => !required.includes(e.requirement));

  if (required.length === 0 && extra.length === 0) {
    return <p style={{ margin: 0 }}>This type of report asks for no photographs.</p>;
  }

  return (
    <div className="ak-field" style={{ gap: '0.8rem' }}>
      {required.map((req) => (
        <RequirementSummary
          key={req}
          incidentId={incidentId}
          requirement={req}
          attached={rows.filter((e) => e.requirement === req)}
        />
      ))}

      {/* Attached against a requirement this reason no longer asks for — an ASC
          report attached before the tick was cleared, say. Hiding it would look
          like the file had been deleted when it is still on the report.

          GROUPED BY REQUIREMENT, exactly as the required list above is. Mapping
          the rows directly would give two orphan photos of the same requirement
          two separate cards each badged "1 attached", so the same two photos
          would be described differently depending only on whether the reason
          still asks for them. */}
      {[...new Set(extra.map((e) => e.requirement))].map((req) => (
        <RequirementSummary
          key={`extra-${req}`}
          incidentId={incidentId}
          requirement={req}
          attached={extra.filter((e) => e.requirement === req)}
          alsoAttached
        />
      ))}
    </div>
  );
}

/**
 * One checklist line, read-only.
 *
 * Three states the admin must be able to tell apart: photographed, skipped with
 * a written reason, and never answered. A SKIP SATISFIES A REQUIREMENT — that
 * is the point of recording one, so the admin sees the box photo is missing
 * BECAUSE the box was discarded rather than seeing a thin report and guessing.
 *
 * Photos and skips are rendered independently rather than as an either/or,
 * because the server's photo-xor-skip rule binds a ROW and not a requirement:
 * an inspector can photograph one side of a box and separately write off the
 * other, and both halves of that account have to survive to this screen.
 */
function RequirementSummary({ incidentId, requirement, attached, alsoAttached = false }) {
  const meta = requirementMeta(requirement);
  const photos = attached.filter((e) => e.photo_path);
  const skips = attached.filter((e) => !e.photo_path);
  const satisfied = attached.length > 0;
  const skippedOnly = satisfied && photos.length === 0;

  return (
    <fieldset
      className="ak-card"
      style={{ border: '1px solid var(--ak-line)', padding: '0.75rem', margin: 0 }}
    >
      <legend className="ak-label" style={{ padding: '0 0.35rem' }}>
        {alsoAttached ? `Also attached: ${meta.label}` : meta.label}{' '}
        {satisfied ? (
          <span className={`ak-badge ${skippedOnly ? 'ak-badge--gray' : 'ak-badge--green'}`}>
            {skippedOnly ? 'Skipped' : `${photos.length} attached`}
          </span>
        ) : (
          <span className="ak-badge ak-badge--amber">Outstanding</span>
        )}
      </legend>

      {!satisfied && (
        <div className="ak-muted" style={{ fontSize: '0.82rem' }}>
          Nothing was attached against this requirement, and no reason was given.
        </div>
      )}

      {photos.length > 0 && (
        <div style={{ display: 'flex', gap: '0.75rem', flexWrap: 'wrap', alignItems: 'flex-start' }}>
          {photos.map((e, i) => (
            <div
              key={e.evidence_id}
              style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', alignItems: 'flex-start' }}
            >
              {/* NUMBERED when there is more than one. `box_two_sides` expects
                  two photographs, and two buttons both announcing "Two sides of
                  the box — click to enlarge" leave a screen-reader user unable
                  to tell which one they have already opened. */}
              <EvidenceThumbnail
                incidentId={incidentId}
                evidenceId={e.evidence_id}
                enlargeable
                alt={photos.length > 1 ? `${meta.label} (${i + 1} of ${photos.length})` : meta.label}
              />

              {/* Provenance matters more to the person approving than to the
                  one who took the photo. A null source renders nothing rather
                  than "unknown" — see PhotoSourceBadge, which owns that rule. */}
              <PhotoSourceBadge source={e.photo_source} />
            </div>
          ))}
        </div>
      )}

      {skips.map((e) => (
        <div key={e.evidence_id} style={{ fontSize: '0.85rem' }}>
          {/* The fallback is unreachable through the API, which rejects a row
              with neither photo nor reason. It is here because this is the one
              place in the file that could otherwise render a bare "Skipped:"
              with no words after it — the exact shape of the bug being fixed. */}
          <strong>Skipped:</strong> {e.skipped_reason || 'No reason was recorded.'}
        </div>
      ))}
    </fieldset>
  );
}
