import React from 'react';
import {
  outcomeLabel, outcomeRemovesStock,
  supplierWarrantyDetail, supplierWarrantyMeta, warrantyStateMeta,
} from '../../utils/incidentItems';
import { UnitIdentity } from './UnitIdentity';

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
