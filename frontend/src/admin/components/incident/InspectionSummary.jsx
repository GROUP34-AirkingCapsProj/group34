import React from 'react';
import {
  itemStatusMeta, outcomeLabel, outcomeRemovesStock, unitRoleLabel,
  supplierWarrantyDetail, supplierWarrantyMeta, warrantyStateMeta,
} from '../../utils/incidentItems';
import { formatPieces } from '../../utils/packageFormat';

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

/** When the inspection happened, in the reader's own locale. */
const formatInstant = (iso) => {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d.toLocaleString();
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
 * Deliberately mirrors ItemOutcomeRow's header and badges. The admin holds the
 * signed hardcopy the inspector printed from that same layout, and two
 * renderings that describe the same unit differently is how a mismatch gets
 * waved through.
 */
function UnitOutcomeSummary({ item }) {
  const status = itemStatusMeta(item.status);
  const warranty = warrantyStateMeta(item.warranty_state);
  const supplier = supplierWarrantyMeta(item.supplier_warranty?.state);
  const supplierDetail = supplierWarrantyDetail(item.supplier_warranty);
  const qtyLabel = formatPieces(item.quantity, item.pieces_per_package, item.package_unit);
  const settled = item.status !== 'quarantined';

  return (
    <fieldset className="incdoc__unit">
      <legend className="incdoc__unit-legend">
        {unitRoleLabel(item.unit_role)}{' '}
        <span className={`ak-badge ${status.badge}`}>{status.label}</span>
      </legend>

      <div style={{ fontSize: '0.86rem', marginBottom: '0.5rem' }}>
        <strong>{item.product_name || `#${item.product_id}`}</strong> · {qtyLabel}
        {item.batch_id ? ` · batch #${item.batch_id}` : ''}
        {item.model_code ? ` · model ${item.model_code}` : ''}
        {item.serial_number ? ` · SN ${item.serial_number}` : ''}
      </div>

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

      {item.outcome ? (
        <div style={{ fontSize: '0.86rem' }}>
          {settled ? 'Settled as ' : 'The inspector decided: '}
          <strong>{outcomeLabel(item.outcome)}</strong>
          {item.outcome_note ? ` — ${item.outcome_note}` : ''}

          {/* Said on the row, not in a footnote. Confirming is the one and only
              moment stock moves, and the person confirming is not the person who
              chose this. */}
          {!settled && outcomeRemovesStock(item.outcome) && (
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
  const lines = [
    ['Authorised representative', report?.signed_by_representative],
    ['Branch manager', report?.signed_by_branch_manager],
    ['Date signed', report?.signed_at],
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
