import React, { useMemo } from 'react';

// Ordered lifecycle stages (Pending is shown as "Submitted").
const STEPS = ['Submitted', 'Approved', 'In Transit', 'Received'];
const STATUS_STEP = { Pending: 0, Approved: 1, 'In Transit': 2, Received: 3 };

const fmt = (v) => {
  if (!v) return '';
  const d = new Date(v);
  return Number.isNaN(d.getTime())
    ? String(v)
    : d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const userName = (u) => {
  if (!u) return 'System';
  return u.username || [u.first_name, u.last_name].filter(Boolean).join(' ') || `User #${u.user_id}`;
};

const statusOf = (t) => t?.status?.status_name || t?.status_name || 'Pending';

/** Horizontal progress stepper — glanceable stage of a transfer. */
export const TransferSteps = ({ transfer }) => {
  const status = statusOf(transfer);
  if (status === 'Draft' || status === 'Partial') {
    return <div className="tf-steps">
      <span className="ak-badge ak-badge--amber">{status}</span>
      <span className="ak-muted" style={{ marginLeft: 8 }}>{status === 'Draft'
        ? 'This request can be edited and submitted again.'
        : 'Outstanding quantities remain. Continue fulfillment to complete this request.'}</span>
    </div>;
  }
  if (status === 'Rejected') {
    return (
      <div className="tf-steps tf-steps--rejected">
        <span className="ak-badge ak-badge--red">Rejected</span>
        <span className="ak-muted" style={{ marginLeft: 8 }}>This request was rejected by the analyst.</span>
      </div>
    );
  }
  const current = STATUS_STEP[status] ?? 0;
  return (
    <ol className="tf-steps">
      {STEPS.map((label, i) => (
        <li
          key={label}
          className={`tf-step${i < current ? ' is-done' : ''}${i === current ? ' is-current' : ''}`}
        >
          <span className="tf-step-dot">{i < current ? '✓' : i + 1}</span>
          <span className="tf-step-label">{label}</span>
        </li>
      ))}
    </ol>
  );
};

/** Vertical history timeline built from transfer_tracking events. */
export const TransferHistory = ({ transfer }) => {
  const events = useMemo(() => {
    const list = [...(transfer?.transferTracking || transfer?.transfer_tracking || [])];
    return list.sort(
      (a, b) => new Date(a.recorded_at || a.created_at || 0) - new Date(b.recorded_at || b.created_at || 0),
    );
  }, [transfer]);

  return (
    <div style={{ marginTop: 18 }}>
      <h4 className="tf-panel-section-title">History</h4>
      {events.length === 0 ? (
        <p className="ak-muted">No history recorded yet.</p>
      ) : (
        <ul className="tf-timeline">
          {events.map((e, i) => (
            <li key={e.id ?? i} className="tf-timeline-item">
              <span className="tf-timeline-dot" />
              <div className="tf-timeline-body">
                <div className="tf-timeline-note">{e.status_note}</div>
                <div className="tf-timeline-meta">
                  {userName(e.recordedBy || e.recorded_by_user)} · {fmt(e.recorded_at || e.created_at)}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};
