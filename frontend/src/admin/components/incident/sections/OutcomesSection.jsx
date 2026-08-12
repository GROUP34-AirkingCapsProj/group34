import React from 'react';
import DocumentSection from './DocumentSection';

/**
 * What happens to each unit — decided per unit, because a split system can have
 * one half scrapped and the other put back into stock.
 *
 * The choices are NOT listed here as a fixed set, even in the greyed preview.
 * The server serves `allowed_outcomes` per item, filtered by that unit's
 * warranty state, and the client must never re-derive the rule: offering a
 * warranty claim on an out-of-warranty unit wastes a warranty officer's time
 * and stays invisible until they open it and find nothing to claim against.
 * Task 9 renders the buttons it is given.
 */
export default function OutcomesSection({ state, units, children }) {
  const rows = Array.isArray(units) ? units : [];

  return (
    <DocumentSection
      id="incdoc-outcomes"
      title="Outcome per unit"
      state={state}
      hint={state === 'awaiting'
        ? 'The inspector decides each unit separately. Confirming those decisions is what finally moves the stock count — filing this report does not.'
        : undefined}
    >
      {children ?? (
        <ul className="incdoc__checklist">
          {rows.map((u, i) => (
            <li key={u.uid}>Unit {i + 1} — awaiting inspection</li>
          ))}
          {rows.length === 0 && <li>Awaiting inspection.</li>}
        </ul>
      )}
    </DocumentSection>
  );
}
