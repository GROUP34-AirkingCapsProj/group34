import React from 'react';
import {
  outcomeLabel, outcomeRemovesStock,
  supplierWarrantyDetail, supplierWarrantyMeta, warrantyStateMeta,
} from '../../utils/incidentItems';
import { UnitIdentity } from './UnitIdentity';
import { EvidenceThumbnail, PhotoSourceBadge } from './EvidencePhoto';
import { requirementMeta, requirementsFor } from '../../utils/incidentDocument';

/**
 * The inspector's half of the document, READ-ONLY — the admin's view.
 *
 * The mirror of InspectionFields.jsx, which holds the editable versions. They
 * are separate files rather than one mode-aware set so neither grows a branch
 * inside it, and so the admin's rendering has no write path to reach by
 * accident: nothing here imports a mutating API call or `toast`.
 *
 * That matters beyond tidiness. setOutcome is gated on `incident_reports.inspect`
 * while decide is gated on `resolve`, so an admin cannot change an outcome even
 * if a control were rendered — a dropdown here would 403 rather than save.
 *
 * ONE RULE GOVERNS EVERY COMPONENT BELOW: an absence is stated in words, never
 * rendered as blank space. "No outcome was recorded on this unit" is
 * information. An empty section is what caused this file to be written — the
 * admin's whole lower half rendered blank and they were asked to confirm a
 * stock movement they could not see.
 */

/**
 * When the inspection happened, in the reader's own locale.
 *
 * A NAMED month, explicitly. The bare toLocaleString() this replaced rendered
 * 8/13/2026 for one admin and 13/08/2026 for another, and for the first twelve
 * days of any month those two orderings are the same string meaning different
 * days — on a screen whose whole job is cross-checking a date against a sheet
 * in the reader's hand. Seconds are dropped: nobody reconciles to the second.
 */
const formatInstant = (iso) => {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleString(undefined, {
    year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  });
};

/** What the inspection concluded, who concluded it, and when. */
export function FindingsSummary({ report }) {
  const findings = String(report?.inspection_findings || '').trim();
  const who = report?.inspected_by_name;
  const when = formatInstant(report?.inspected_at);

  return (
    <>
      {findings ? (
        // pre-wrap, because the inspector's line breaks separate observations.
        // Collapsed into one paragraph they read as a single rambling sentence.
        <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>{findings}</p>
      ) : (
        <p className="ak-muted" style={{ margin: 0 }}>
          The inspector recorded no written findings.
        </p>
      )}

      {/* Stated either way. Rendering nothing when it is false would leave the
          admin unable to tell "not checked by a service centre" apart from "this
          screen does not show that". */}
      <p style={{ margin: '0.6rem 0 0', fontSize: '0.86rem' }}>
        {report?.checked_by_asc
          ? 'Checked by an authorised service centre (ASC).'
          : 'Not checked by an authorised service centre.'}
      </p>

      {/* Whose judgement the admin is confirming. Both fields are in the payload
          and were previously shown nowhere at all. */}
      <p className="ak-muted" style={{ margin: '0.3rem 0 0', fontSize: '0.82rem' }}>
        {who || when
          ? `Inspected by ${who || 'an unnamed user'}${when ? ` on ${when}` : ''}.`
          : 'No inspection has been submitted on this report yet.'}
      </p>
    </>
  );
}

/** Every unit on the report and what the inspector decided about each. */
export function OutcomeSummary({ items }) {
  const rows = Array.isArray(items) ? items : [];

  if (rows.length === 0) {
    return <p style={{ margin: 0 }}>This report names no physical units.</p>;
  }

  return rows.map((item) => <UnitOutcomeSummary key={item.item_id} item={item} />);
}

/**
 * One unit, as the admin reads it.
 *
 * Its identity line comes from the shared UnitIdentity for a reason: the
 * inspector's editable row and this read-only one are two views of the same
 * facts for the same reader, one deciding and one confirming. If they name the
 * unit differently the admin cannot tell whether the decision in front of them
 * belongs to the unit in front of them, so the two must not be able to drift.
 *
 * Below the identity the two DO diverge, deliberately: the inspector needs the
 * `.help` text explaining what each warranty state permits, and the admin —
 * who cannot change any of it — needs the badges and nothing else.
 */
function UnitOutcomeSummary({ item }) {
  const warranty = warrantyStateMeta(item.warranty_state);
  const supplier = supplierWarrantyMeta(item.supplier_warranty?.state);
  const supplierDetail = supplierWarrantyDetail(item.supplier_warranty);
  const settled = item.status !== 'quarantined';

  return (
    <fieldset className="incdoc__unit">
      <UnitIdentity item={item} />

      {/* Both clocks, because they disagree by design: stock on the shelf has
          supplier cover and no customer warranty at all. An admin reading only
          the customer badge would think an uncovered unit was being returned. */}
      {warranty && (
        <div style={{ marginBottom: '0.35rem' }}>
          <span className={`ak-badge ${warranty.badge}`}>{warranty.label}</span>
        </div>
      )}
      {supplier && (
        <div style={{ marginBottom: '0.5rem' }}>
          <span className={`ak-badge ${supplier.badge}`}>{supplier.label}</span>{' '}
          {supplierDetail && (
            <span className="ak-muted" style={{ fontSize: '0.8rem' }}>{supplierDetail}</span>
          )}
        </div>
      )}

      {/* SETTLED IS ASKED FIRST, and the order is load-bearing. A unit can be
          settled with no outcome at all: release() dismisses a report by moving
          the status and deliberately ignores whatever the inspector recorded.
          Asking about the outcome first calls that unit "undecided" and invites
          the admin to chase a decision on something already closed. */}
      {settled ? (
        <div className="ak-muted" style={{ fontSize: '0.86rem' }}>
          {item.outcome ? (
            <>
              Settled as <strong>{outcomeLabel(item.outcome)}</strong>
              {item.outcome_note ? ` — ${item.outcome_note}` : ''}
            </>
          ) : (
            // Said in full rather than as "Settled as —". This unit is closed
            // and nothing was applied to it; a dash leaves the admin unable to
            // tell that apart from a value this screen failed to load.
            'Settled with no outcome recorded — the report was dismissed, so nothing was applied to this unit.'
          )}
        </div>
      ) : item.outcome ? (
        <div style={{ fontSize: '0.86rem' }}>
          The inspector decided: <strong>{outcomeLabel(item.outcome)}</strong>
          {item.outcome_note ? ` — ${item.outcome_note}` : ''}

          {/* Said on the row, not in a footnote. Confirming is the one and only
              moment stock moves, and the person confirming is not the person who
              chose this. Unreachable for a settled unit by construction now:
              that unit was deducted by an earlier confirmation, and saying this
              over it would be a warning about a movement already made. */}
          {outcomeRemovesStock(item.outcome) && (
            <div style={{ marginTop: '0.35rem' }}>
              <span className="ak-badge ak-badge--amber">
                Confirming deducts this unit from stock.
              </span>
            </div>
          )}
        </div>
      ) : (
        <div style={{ fontSize: '0.86rem' }}>
          <span className="ak-badge ak-badge--amber">No outcome recorded</span>{' '}
          <span className="ak-muted">
            The inspector left this unit undecided, so there is nothing to apply to it.
          </span>
        </div>
      )}
    </fieldset>
  );
}

/**
 * The two names on the hardcopy.
 *
 * The system records that signing happened; what is legally signed is the
 * printed sheet. The admin has that sheet in hand, and these three values are
 * what they check it against — so a blank box has to read "not recorded" rather
 * than looking like a field this screen forgot to render.
 */
export function SignatureSummary({ report }) {
  // Trimmed, exactly as FindingsSummary trims its findings: a name of spaces is
  // not a signature, and rendered as-is it is indistinguishable from a signed
  // line — the one thing this block must never show.
  const signed = (v) => String(v ?? '').trim() || null;

  const lines = [
    ['Authorised representative', signed(report?.signed_by_representative)],
    ['Branch manager', signed(report?.signed_by_branch_manager)],
    ['Date signed', signed(report?.signed_at)],
  ];

  return (
    <div className="incdoc__row">
      {lines.map(([label, value]) => (
        <div key={label}>
          <span className="ak-label">{label}</span>
          <div>
            {value || <span className="ak-muted">Not recorded</span>}
          </div>
        </div>
      ))}
    </div>
  );
}

/**
 * The evidence checklist as the ADMIN reads it — the same requirements the
 * inspector answered, with no way to add, skip or remove anything.
 *
 * This is the section the whole change exists for: it rendered completely
 * empty, so an admin approving a write-off could not see a single photograph of
 * the unit being written off.
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
      style={{ border: '1px solid var(--ak-line, #ddd)', padding: '0.75rem', margin: 0 }}
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
          {photos.map((e) => (
            <div
              key={e.evidence_id}
              style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', alignItems: 'flex-start' }}
            >
              <EvidenceThumbnail
                incidentId={incidentId}
                evidenceId={e.evidence_id}
                enlargeable
                alt={meta.label}
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
          <strong>Skipped:</strong> {e.skipped_reason}
        </div>
      ))}
    </fieldset>
  );
}
