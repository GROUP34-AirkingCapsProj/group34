import React from 'react';

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
