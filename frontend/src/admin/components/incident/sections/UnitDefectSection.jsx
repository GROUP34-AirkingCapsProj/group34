import React from 'react';
import DocumentSection, { DocField, DocSelect } from './DocumentSection';
import { ROOT_CAUSES, SUSPECTED_CAUSES } from '../../../utils/incidentReasons';

/**
 * "Unit defect" on the client's paper form — where the fault came from.
 *
 * A missing report has no defect but it does have a cause, and the same block
 * asks it. Dropping the question because the paper heading says "defect" would
 * lose the only field that separates a suspected theft from something
 * misplaced in the building, which is the difference between a security matter
 * and a stocktake.
 *
 * Neither answer is offered on a return: whether a returned unit is faulty is
 * an inspection OUTCOME, not a reporter's claim, and asking here would let the
 * reporter pre-empt the inspector.
 */
export default function UnitDefectSection({ state, form, patch, disabled }) {
  const readOnly = state !== 'editable';
  const missing = form.reason === 'missing';

  return (
    <DocumentSection
      id="incdoc-defect"
      title={missing ? 'How it went missing' : 'Unit defect'}
      state={state}
      hint={missing
        ? 'Where it was last seen, and what you think happened to it.'
        : 'Where the fault came from. The inspector decides what happens to the unit; this is what you observed.'}
    >
      {missing ? (
        <>
          <DocSelect
            id="incdoc-suspected-cause"
            label="What do you think happened? *"
            value={form.suspectedCause}
            onChange={(v) => patch({ suspectedCause: v })}
            placeholder="Choose one…"
            options={SUSPECTED_CAUSES}
            disabled={disabled || readOnly}
          />
          <DocField
            id="incdoc-last-known"
            label="Where was it last seen?"
            value={form.lastKnownLocation}
            onChange={(v) => patch({ lastKnownLocation: v })}
            placeholder="e.g. Aisle C, bottom rack"
            disabled={disabled || readOnly}
          />
        </>
      ) : (
        <DocSelect
          id="incdoc-root-cause"
          label="Where did the fault come from? *"
          value={form.rootCause}
          onChange={(v) => patch({ rootCause: v })}
          placeholder="Choose one…"
          options={ROOT_CAUSES}
          disabled={disabled || readOnly}
        />
      )}
    </DocumentSection>
  );
}
