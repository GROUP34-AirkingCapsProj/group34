import React from 'react';
import DocumentSection from './DocumentSection';
import { evidenceRequirementsFor, requirementMeta } from '../../../utils/incidentDocument';

/**
 * The photographic evidence checklist — the inspector's, not the reporter's.
 *
 * In `file` mode it is rendered as a greyed list of what WILL be asked for
 * rather than being absent, so a reporter can see what the inspection needs and
 * bring the box or the warranty card to the bench instead of throwing it away.
 * Hiding it would be a small tidiness win paid for with discarded evidence.
 *
 * The list is reason-driven, not type-driven: a wrong-delivery return proves
 * the delivery was wrong — the wrong/correct pair plus the receipt — while a
 * wrong-quantity or unwanted return proves whether the goods are still
 * sellable. Mirrors App\Models\IncidentEvidence::requiredFor(); the server is
 * the authority and this list exists so nobody is told after a 422.
 *
 * Both later modes replace the preview with `children`: `inspect` renders
 * EvidenceChecklist, which writes, and `decide` renders EvidenceSummary, which
 * shows the same evidence read-only.
 */
export default function AttachmentsSection({ state, reason, returnReasonCode, children }) {
  const required = evidenceRequirementsFor(reason, returnReasonCode);

  return (
    <DocumentSection
      id="incdoc-attachments"
      title="Attachments"
      state={state}
      hint={state === 'awaiting'
        ? 'The inspector attaches these. Nothing here is yours to fill in — but if any of it is about to be thrown away, keep it.'
        : undefined}
    >
      {/* `||`, NOT `??`. A child gated on a boolean arrives here as `false`,
          and `false ?? fallback` is `false` — which React renders as nothing.
          That is exactly how four empty sections reached the admin. */}
      {children || (
        <ul className="incdoc__checklist">
          {required.map((req) => (
            <li key={req}>{requirementMeta(req).label}</li>
          ))}
          {required.length === 0 && (
            <li>Nothing — this type of report asks for no photographs.</li>
          )}
        </ul>
      )}
    </DocumentSection>
  );
}
