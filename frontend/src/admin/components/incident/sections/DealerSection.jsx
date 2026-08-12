import React from 'react';
import DocumentSection, { DocField } from './DocumentSection';

/**
 * The dealer the unit went out through.
 *
 * Filled by the REPORTER, not the inspector — the reporter is the one who knows
 * which dealer handled it. By the time an inspector opens the report, that
 * trail is a phone call away.
 *
 * Every field is optional, deliberately. A reporter genuinely may not know the
 * dealer's email, and blocking submission on it does not produce the email — it
 * produces "n/a" typed into a field that then prints on a client's document.
 */
export default function DealerSection({ state, form, patch, disabled }) {
  const readOnly = state !== 'editable';
  const set = (key) => (v) => patch({ [key]: v });

  return (
    <DocumentSection
      id="incdoc-dealer"
      title="Dealer"
      state={state}
      hint={readOnly ? undefined : 'These print on the client’s copy. Fill in what you know — all of it is optional, and a reviewer can chase the rest.'}
    >
      <DocField id="incdoc-dealer-name" label="Dealer name"
        value={form.dealerName} onChange={set('dealerName')} disabled={disabled || readOnly} />

      <div className="incdoc__row">
        <DocField id="incdoc-dealer-phone" label="Phone" maxLength={40}
          value={form.dealerPhone} onChange={set('dealerPhone')} disabled={disabled || readOnly} />
        <DocField id="incdoc-dealer-mobile" label="Mobile" maxLength={40}
          value={form.dealerMobile} onChange={set('dealerMobile')} disabled={disabled || readOnly} />
        <DocField id="incdoc-dealer-email" label="Email" type="email"
          value={form.dealerEmail} onChange={set('dealerEmail')} disabled={disabled || readOnly} />
      </div>

      <DocField id="incdoc-branch-address" label="Branch address" textarea maxLength={255}
        value={form.branchAddress} onChange={set('branchAddress')} disabled={disabled || readOnly} />
    </DocumentSection>
  );
}
