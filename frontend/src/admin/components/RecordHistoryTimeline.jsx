import React, { useState } from 'react';
import '../styles/record_history.css';

function fmt(ts) {
  if (!ts) return '';
  try {
    return new Date(ts).toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
  } catch {
    return ts;
  }
}

function Milestone({ m }) {
  const [open, setOpen] = useState(false);
  const hasChanges = Array.isArray(m.changes) && m.changes.length > 0;
  return (
    <li className="rh-item">
      <span className={`rh-dot rh-dot--${m.type}`} />
      <div className="rh-head">
        <span className="rh-label">{m.label}</span>
        <span className="rh-meta">{fmt(m.timestamp)}{m.actor_name ? ` · ${m.actor_name}` : ''}</span>
        {hasChanges && (
          <button type="button" className="rh-toggle" onClick={() => setOpen((v) => !v)}>
            {open ? '▴ hide' : '▾ details'}
          </button>
        )}
      </div>
      {open && hasChanges && (
        <div className="rh-changes">
          {m.changes.map((c, i) => (
            <div className="rh-change" key={i}>
              <strong>{c.field}:</strong>{' '}
              <span className="rh-from">{c.from == null || c.from === '' ? '—' : String(c.from)}</span>
              {' → '}
              <span>{c.to == null || c.to === '' ? '—' : String(c.to)}</span>
            </div>
          ))}
        </div>
      )}
    </li>
  );
}

export default function RecordHistoryTimeline({ milestones, loading }) {
  if (loading) return <div className="rh-empty">Loading history…</div>;
  if (!milestones || milestones.length === 0) return <div className="rh-empty">No history recorded yet.</div>;
  return (
    <ul className="rh-timeline">
      {milestones.map((m, i) => <Milestone m={m} key={i} />)}
    </ul>
  );
}
