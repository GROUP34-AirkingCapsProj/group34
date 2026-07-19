import React from 'react';
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

/* A small glyph per milestone type, drawn inside the timeline node. */
function NodeIcon({ type }) {
  const p = { fill: 'none', stroke: 'currentColor', strokeWidth: 2.4, strokeLinecap: 'round', strokeLinejoin: 'round' };
  switch (type) {
    case 'created':   return <svg viewBox="0 0 24 24" {...p}><path d="M12 5v14M5 12h14" /></svg>;
    case 'verified':  return <svg viewBox="0 0 24 24" {...p}><path d="M20 6 9 17l-5-5" /></svg>;
    case 'approved':
    case 'status':    return <svg viewBox="0 0 24 24" {...p}><path d="M9 11l3 3L22 4" /><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" /></svg>;
    case 'received':  return <svg viewBox="0 0 24 24" {...p}><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" /><path d="M3.3 7 12 12l8.7-5M12 22V12" /></svg>;
    case 'rejected':  return <svg viewBox="0 0 24 24" {...p}><path d="M18 6 6 18M6 6l12 12" /></svg>;
    case 'converted': return <svg viewBox="0 0 24 24" {...p}><path d="M5 12h14M12 5l7 7-7 7" /></svg>;
    case 'archived':  return <svg viewBox="0 0 24 24" {...p}><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4" /></svg>;
    default:          return <svg viewBox="0 0 24 24" {...p}><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>;
  }
}

function Milestone({ m }) {
  const hasChanges = Array.isArray(m.changes) && m.changes.length > 0;
  return (
    <li className={`rh-item rh-item--${m.type}`}>
      <span className={`rh-dot rh-dot--${m.type}`}><NodeIcon type={m.type} /></span>
      <div className="rh-head">
        <span className="rh-label">{m.label}</span>
        <span className="rh-meta">{fmt(m.timestamp)}{m.actor_name ? ` · ${m.actor_name}` : ''}</span>
      </div>
      {hasChanges && (
        <div className="rh-changes">
          {m.changes.map((c, i) => (
            <div className="rh-change" key={i}>
              <strong>{c.field}:</strong>{' '}
              <span className="rh-from">{c.from == null || c.from === '' ? '—' : String(c.from)}</span>
              {' → '}
              <span className="rh-to">{c.to == null || c.to === '' ? '—' : String(c.to)}</span>
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
