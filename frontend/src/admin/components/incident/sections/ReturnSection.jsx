import React from 'react';
import DocumentSection, { DocField, DocSelect } from './DocumentSection';
import { RETURN_COUNTERPARTIES, RETURN_REASON_CODES } from '../../../utils/incidentReasons';

/**
 * The two independent facts a return records: who the counterparty was, and
 * why.
 *
 * Which way the goods travel is DERIVED from the counterparty and never asked.
 * A reporter who answers "from a customer" and then "outbound" has created a
 * contradiction with no correct resolution, and there is no case where the two
 * genuinely disagree.
 *
 * It sits directly under the type because it changes what the rest of the form
 * asks for: `wrong_product` adds the wrong/correct pair to every unit, and the
 * evidence checklist below the rule is reason-driven, not type-driven.
 */
export default function ReturnSection({ state, form, patch, disabled }) {
  const readOnly = state !== 'editable';
  const counterparty = RETURN_COUNTERPARTIES.find((c) => c.value === form.returnCounterparty);
  const reasonCode = RETURN_REASON_CODES.find((r) => r.value === form.returnReasonCode);

  return (
    <DocumentSection
      id="incdoc-return"
      title="The return"
      state={state}
      hint="Who the goods are coming from decides whether they are arriving or leaving — you are not asked which, because the two can never disagree."
    >
      <div className="incdoc__row">
        <DocSelect
          id="incdoc-return-counterparty"
          label="Who is it from, or going to? *"
          value={form.returnCounterparty}
          onChange={(v) => patch({ returnCounterparty: v })}
          placeholder="Choose one…"
          options={RETURN_COUNTERPARTIES.map((c) => ({ value: c.value, label: c.label }))}
          disabled={disabled || readOnly}
          hint={counterparty?.help}
        />
        <DocSelect
          id="incdoc-return-reason-code"
          label="Why is it being returned? *"
          value={form.returnReasonCode}
          onChange={(v) => patch({ returnReasonCode: v })}
          placeholder="Choose one…"
          options={RETURN_REASON_CODES.map((r) => ({ value: r.value, label: r.label }))}
          disabled={disabled || readOnly}
          hint={reasonCode?.help}
        />
      </div>

      <DocField
        id="incdoc-return-reason"
        label="Anything else about the return (optional)"
        value={form.returnReason}
        onChange={(v) => patch({ returnReason: v })}
        placeholder="e.g. the customer refused delivery at the gate"
        disabled={disabled || readOnly}
      />
    </DocumentSection>
  );
}
