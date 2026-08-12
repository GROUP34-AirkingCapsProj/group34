import React from 'react';

/**
 * One section of the incident report form.
 *
 * Three states, never four: `editable` (this is your half, and it is your
 * turn), `readonly` (filled in by someone earlier, shown in full and locked)
 * and `awaiting` (nobody has written here yet and it is not your job).
 *
 * There is deliberately no "hidden" state for a completed section. An inspector
 * must see what the reporter claimed — that is half of what they are checking —
 * and an admin must see both. Hiding a finished section to tidy the screen is
 * how the person with the authority to approve ends up approving something they
 * never read.
 */
export default function DocumentSection({ id, title, hint, state = 'editable', children }) {
  const badge = state === 'awaiting'
    ? 'The inspector fills this in'
    : state === 'readonly' ? 'Locked' : null;

  return (
    <section className={`incdoc__section incdoc__section--${state}`} aria-labelledby={`${id}-title`}>
      <div className="incdoc__section-head">
        <h3 className="incdoc__section-title" id={`${id}-title`}>{title}</h3>
        {badge && <span className="ak-badge ak-badge--gray">{badge}</span>}
      </div>
      {hint && <p className="incdoc__hint">{hint}</p>}
      <div className="incdoc__body">{children}</div>
    </section>
  );
}

/** One labelled text-ish field. The alternative is forty near-identical blocks. */
export function DocField({
  id, label, value, onChange, type = 'text', textarea = false, rows = 2,
  maxLength = 150, placeholder, disabled, hint, max,
}) {
  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={id}>{label}</label>
      {textarea ? (
        <textarea
          id={id}
          className="ak-input"
          rows={rows}
          maxLength={maxLength}
          value={value ?? ''}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      ) : (
        <input
          id={id}
          className="ak-input"
          type={type}
          maxLength={type === 'number' ? undefined : maxLength}
          max={max}
          value={value ?? ''}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      )}
      {hint && <span className="ak-muted" style={{ fontSize: '0.78rem' }}>{hint}</span>}
    </div>
  );
}

/** One labelled dropdown over `{ value, label }` options. */
export function DocSelect({ id, label, value, onChange, options, placeholder, disabled, hint }) {
  return (
    <div className="ak-field">
      <label className="ak-label" htmlFor={id}>{label}</label>
      <select
        id={id}
        className="ak-select"
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      >
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
      {hint && <span className="ak-muted" style={{ fontSize: '0.78rem' }}>{hint}</span>}
    </div>
  );
}
