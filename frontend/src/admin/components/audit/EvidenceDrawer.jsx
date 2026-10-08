import React, { useCallback, useEffect, useState } from 'react';
import { auditEvidenceAPI } from '../../services/api';
import LifecycleTimeline from './LifecycleTimeline';
import '../../styles/audit_evidence.css';

const fmtMoney = (n) =>
  n === null || n === undefined
    ? '—'
    : '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('en-PH');
};

/**
 * Level 4: one event, with the document, the actor chain, and the change history
 * audit_trail already recorded against it.
 *
 * The history is served by the same RecordHistoryService the existing record
 * timelines use, so this drawer and those screens cannot tell different stories
 * about the same record.
 */
const EvidenceDrawer = ({ correlationId, onClose }) => {
  const [bundle, setBundle] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [lifecycle, setLifecycle] = useState(null);

  useEffect(() => {
    if (!correlationId) return undefined;

    let cancelled = false;
    setLoading(true);
    setError('');
    setLifecycle(null);

    auditEvidenceAPI
      .getEvent(correlationId)
      .then((res) => {
        if (!cancelled) setBundle(res?.data ?? null);
      })
      .catch((e) => {
        if (!cancelled) setError(e?.message || 'Could not load the evidence for this event.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [correlationId]);

  const openSerial = useCallback((serial) => {
    auditEvidenceAPI
      .getSerialLifecycle(serial)
      .then((res) => setLifecycle({ title: `Serial ${serial}`, rows: res?.data ?? [] }))
      .catch((e) => setError(e?.message || 'Could not load that serial history.'));
  }, []);

  const openBatch = useCallback((batchId) => {
    auditEvidenceAPI
      .getBatchLifecycle(batchId)
      .then((res) => setLifecycle({ title: `Batch #${batchId}`, rows: res?.data ?? [] }))
      .catch((e) => setError(e?.message || 'Could not load that batch history.'));
  }, []);

  if (!correlationId) return null;

  return (
    <aside className="ae-drawer" role="dialog" aria-label="Evidence" data-testid="evidence-drawer">
      <header className="ae-drawer-head">
        <h2 className="ae-card-title">{correlationId}</h2>
        <button type="button" className="ae-btn" onClick={onClose}>Close</button>
      </header>

      {loading && <div className="ae-state">Loading evidence…</div>}
      {error !== '' && <div className="ae-warn" data-testid="drawer-error">{error}</div>}

      {bundle && !loading && (
        <div className="ae-drawer-body">
          <section>
            <h3 className="ae-basis-title">Legs of this event</h3>
            <div className="ae-table-wrap">
              <table className="ae-table">
                <thead>
                  <tr>
                    <th>Type</th><th>Product</th><th>Batch / Serial</th>
                    <th className="ae-num">Qty</th><th className="ae-num">Unit cost</th>
                    <th className="ae-num">Value</th><th>Occurred</th><th>Recorded</th><th>By</th>
                  </tr>
                </thead>
                <tbody>
                  {bundle.legs.map((leg) => (
                    <tr key={leg.ledger_id} className={leg.reverses_ledger_id ? 'ae-row--reversed' : undefined}>
                      <td>
                        {String(leg.movement_type || '').replace(/_/g, ' ')}
                        <span className="ae-flag">{leg.direction}</span>
                      </td>
                      <td>{leg.product_name || '—'}</td>
                      <td>
                        {leg.serial_number ? (
                          <button type="button" className="ae-btn" onClick={() => openSerial(leg.serial_number)}>
                            {leg.serial_number}
                          </button>
                        ) : leg.batch_id ? (
                          <button type="button" className="ae-btn" onClick={() => openBatch(leg.batch_id)}>
                            Batch #{leg.batch_id}
                          </button>
                        ) : '—'}
                      </td>
                      <td className="ae-num">{Number(leg.quantity || 0).toLocaleString('en-PH')}</td>
                      <td className="ae-num">{fmtMoney(leg.unit_cost)}</td>
                      <td className="ae-num">{fmtMoney(leg.extended_value)}</td>
                      <td>{fmtDateTime(leg.occurred_at)}</td>
                      <td>
                        {fmtDateTime(leg.posted_at)}
                        {leg.is_backdated && <span className="ae-flag">Backdated</span>}
                      </td>
                      <td>{leg.actor_name || 'System'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section>
            <h3 className="ae-basis-title">Source document</h3>
            {!bundle.source ? (
              <p className="ae-muted">No source document is linked to this event.</p>
            ) : bundle.source.missing ? (
              <p className="ae-warn" data-testid="source-missing">
                The {bundle.source.type} record #{bundle.source.id} no longer exists. The ledger row survives it
                deliberately — this is evidence that the movement happened, even though the document is gone.
              </p>
            ) : (
              <dl className="ae-kv">
                {Object.entries(bundle.source.attributes)
                  .filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
                  .map(([k, v]) => (
                    <React.Fragment key={k}>
                      <dt>{k.replace(/_/g, ' ')}</dt>
                      <dd>{String(v)}</dd>
                    </React.Fragment>
                  ))}
              </dl>
            )}
          </section>

          <section>
            <h3 className="ae-basis-title">Change history</h3>
            {!bundle.history || bundle.history.length === 0 ? (
              <p className="ae-muted">No recorded changes to this record.</p>
            ) : (
              <ol className="ae-timeline" data-testid="change-history">
                {bundle.history.map((h, i) => (
                  <li key={i}>
                    <strong>{h.label}</strong> — {h.actor_name || 'System'} · {fmtDateTime(h.timestamp)}
                    {Array.isArray(h.changes) && h.changes.length > 0 && (
                      <ul className="ae-basis-list">
                        {h.changes.map((c, j) => (
                          <li key={j} className="ae-muted">
                            {c.field}: {String(c.from ?? '—')} → {String(c.to ?? '—')}
                          </li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ol>
            )}
          </section>

          {lifecycle && <LifecycleTimeline title={lifecycle.title} rows={lifecycle.rows} />}
        </div>
      )}
    </aside>
  );
};

export default EvidenceDrawer;
