import React from 'react';
import { REASON_GROUPS, reasonMeta } from '../../../utils/incidentReasons';

/**
 * Step 1 — what happened, and when. Controlled: every value and setter comes
 * from IncidentFlow, so stepping back and forward loses nothing.
 */
export default function WhatHappenedStep({
  reason, setReason, occurredAt, setOccurredAt, maxOccurredAt, disabled,
}) {
  const meta = reasonMeta(reason);

  return (
    <>
      <fieldset className="ak-field" style={{ border: 'none', margin: 0, padding: 0 }}>
        <legend className="ak-label" style={{ marginBottom: '0.4rem' }}>What happened? *</legend>
        {REASON_GROUPS.map((group) => (
          <div key={group.name} style={{ marginBottom: '0.5rem' }}>
            <div className="ak-muted" style={{ fontSize: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              {group.name}
            </div>
            {group.reasons.map((r) => (
              <label
                key={r.value}
                style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-start', cursor: 'pointer', padding: '0.2rem 0' }}
              >
                <input
                  type="radio"
                  name="incident-flow-reason"
                  value={r.value}
                  checked={reason === r.value}
                  onChange={() => setReason(r.value)}
                  disabled={disabled}
                  style={{ marginTop: '0.2rem' }}
                />
                <span>{r.label}</span>
              </label>
            ))}
          </div>
        ))}
        {meta.help && (
          <p className="ak-muted" style={{ fontSize: '0.8rem', margin: '0.2rem 0 0' }}>{meta.help}</p>
        )}
      </fieldset>

      <div className="ak-field">
        <label className="ak-label" htmlFor="incident-flow-occurred">When did it happen? *</label>
        <input
          id="incident-flow-occurred"
          className="ak-input"
          type="datetime-local"
          value={occurredAt}
          max={maxOccurredAt}
          disabled={disabled}
          onChange={(e) => setOccurredAt(e.target.value)}
          style={{ maxWidth: 260 }}
        />
        <span className="ak-muted" style={{ fontSize: '0.8rem' }}>
          Defaults to now. Set it back if you are reporting something you noticed earlier.
        </span>
      </div>
    </>
  );
}
