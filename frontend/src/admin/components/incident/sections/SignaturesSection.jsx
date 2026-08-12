import React from 'react';
import DocumentSection from './DocumentSection';

/**
 * The two signature lines at the foot of the client's form.
 *
 * The wording is the client's and is kept verbatim — Task 11 prints this same
 * markup under `@media print`, so what the inspector signs is what everyone
 * saw. A separate print layout would be a second thing free to drift.
 */
export default function SignaturesSection({ state, children }) {
  return (
    <DocumentSection
      id="incdoc-signatures"
      title="Signatures"
      state={state}
      hint={state === 'awaiting'
        ? 'Signed off at the inspection, on the printed copy of this same document.'
        : undefined}
    >
      {children ?? (
        <div className="incdoc__row">
          <div>Authorized representative — name and date</div>
          <div>Branch manager — name and date</div>
        </div>
      )}
    </DocumentSection>
  );
}
