import React from 'react';
import '../../styles/audit_evidence.css';

const fmtDate = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
};

/**
 * Level 5: everything that ever happened to one serial or one batch, oldest
 * first — receiving through to final disposition.
 */
const LifecycleTimeline = ({ rows, title }) => (
  <div className="ae-card">
    <div className="ae-card-head">
      <div>
        <h2 className="ae-card-title">{title}</h2>
        <p className="ae-card-meta">Every recorded movement, oldest first</p>
      </div>
    </div>
    {!rows || rows.length === 0 ? (
      <div className="ae-state" data-testid="lifecycle-empty">No recorded movements.</div>
    ) : (
      <ol className="ae-timeline">
        {rows.map((r) => (
          <li key={r.ledger_id}>
            <strong>{String(r.movement_type || '').replace(/_/g, ' ')}</strong>
            <span className="ae-flag">{r.direction}</span>{' '}
            {Number(r.quantity || 0).toLocaleString('en-PH')} pc — {fmtDate(r.occurred_at)}
            <span className="ae-muted"> · {r.actor_name || 'System'}</span>
          </li>
        ))}
      </ol>
    )}
  </div>
);

export default LifecycleTimeline;
