import React from 'react';
import DocumentSection, { DocSelect } from './DocumentSection';
import { INCIDENT_REASONS, reasonMeta } from '../../../utils/incidentReasons';

/**
 * The head of the form: what is being reported, where, and when.
 *
 * The type dropdown drives everything below it — which sections appear at all,
 * what evidence will be asked for, and whether there are physical units to
 * enumerate. It is first because on the client's paper form it is first, and
 * because every other answer depends on it.
 *
 * Location is here rather than in its own block because every type needs it:
 * the report is scoped to one location and the product lookup is scoped to that
 * same location, so nothing below can be answered until it is chosen.
 */
export default function TypeSection({
  state, form, patch, locations, maxOccurredAt, disabled,
}) {
  const meta = reasonMeta(form.reason);
  const readOnly = state !== 'editable';

  return (
    <DocumentSection
      id="incdoc-type"
      title="Incident type"
      state={state}
      hint={readOnly ? undefined : meta.help}
    >
      <div className="incdoc__row">
        <DocSelect
          id="incdoc-reason"
          label="What are you reporting? *"
          value={form.reason}
          onChange={(v) => patch({ reason: v })}
          options={INCIDENT_REASONS.map((r) => ({ value: r.value, label: r.label }))}
          disabled={disabled || readOnly}
        />
        <DocSelect
          id="incdoc-location"
          label="Location *"
          value={form.locationId}
          onChange={(v) => patch({ locationId: v })}
          placeholder="Select a location…"
          options={locations.map((l) => ({
            value: String(l.location_id ?? l.id),
            label: l.location_name || l.name,
          }))}
          disabled={disabled || readOnly}
        />
        <div className="ak-field">
          <label className="ak-label" htmlFor="incdoc-occurred">When did it happen? *</label>
          <input
            id="incdoc-occurred"
            className="ak-input"
            type="datetime-local"
            value={form.occurredAt}
            /* You cannot report something that has not happened yet — the
               server rejects a future instant, so the picker says so first. */
            max={maxOccurredAt}
            onChange={(e) => patch({ occurredAt: e.target.value })}
            disabled={disabled || readOnly}
          />
        </div>
      </div>
    </DocumentSection>
  );
}
