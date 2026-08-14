import React from 'react';
import DocumentSection from './DocumentSection';

/**
 * What the inspection concluded.
 *
 * Empty and greyed during filing. Task 9 passes the findings textarea and the
 * ASC toggle in as `children`; Task 10 passes the recorded text, read-only.
 */
export default function FindingsSection({ state, children }) {
  return (
    <DocumentSection
      id="incdoc-findings"
      title="Findings"
      state={state}
      hint={state === 'awaiting'
        ? 'Filled in at the inspection, once someone has the unit in front of them.'
        : undefined}
    >
      {/* `||`, not `??`: `false ?? fallback` is `false`, so a boolean-gated
          child would silently erase this fallback. */}
      {children || <p style={{ margin: 0 }}>Not inspected yet.</p>}
    </DocumentSection>
  );
}
