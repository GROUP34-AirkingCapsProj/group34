import React, { useCallback, useEffect, useState } from 'react';
import { auditEvidenceAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import '../../styles/audit_evidence.css';

const fmtDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('en-PH');
};

const CHECK_LABELS = {
  source_vs_ledger: 'Source vs ledger',
  ledger_vs_movements: 'Ledger vs FIFO record',
  ledger_vs_stock: 'Ledger vs stock on hand',
  check_failed: 'Drift check failed',
};

/**
 * Open drift findings.
 *
 * A finding stays open until somebody writes down what they did about it, which
 * is what turns a discrepancy into an audit trail of its own. Closing one with no
 * explanation would leave the discrepancy unexplained while looking dealt with,
 * so the note is required rather than optional.
 */
const ExceptionsPanel = ({ canResolve }) => {
  const [findings, setFindings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notes, setNotes] = useState({});
  const [savingId, setSavingId] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    setError('');
    auditEvidenceAPI
      .getExceptions()
      .then((res) => setFindings(Array.isArray(res?.data) ? res.data : []))
      .catch((e) => setError(e?.message || 'Could not load exceptions.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  const resolve = async (findingId) => {
    const note = (notes[findingId] || '').trim();
    if (note.length < 3) {
      toast.error('Describe what was done about this finding before resolving it.');
      return;
    }

    setSavingId(findingId);
    try {
      await auditEvidenceAPI.resolveException(findingId, note);
      toast.success('Finding resolved.');
      load();
    } catch (e) {
      toast.error(e?.message || 'Could not resolve that finding.');
    } finally {
      setSavingId(null);
    }
  };

  return (
    <div className="ae-card">
      <div className="ae-card-head">
        <div>
          <h2 className="ae-card-title">Exceptions</h2>
          <p className="ae-card-meta">Open drift findings from the nightly check</p>
        </div>
        <button type="button" className="ae-btn" onClick={load}>Refresh</button>
      </div>

      {loading ? (
        <div className="ae-state">Loading exceptions…</div>
      ) : error !== '' ? (
        <div className="ae-warn" data-testid="exceptions-error">{error}</div>
      ) : findings.length === 0 ? (
        <div className="ae-state" data-testid="exceptions-empty">
          No open findings. The last drift check found the ledger and its sources in agreement.
        </div>
      ) : (
        <div className="ae-table-wrap">
          <table className="ae-table">
            <thead>
              <tr>
                <th>Check</th><th>Period</th><th className="ae-num">Expected</th>
                <th className="ae-num">Actual</th><th className="ae-num">Variance</th>
                <th>Detail</th><th>Detected</th><th>Resolution</th>
              </tr>
            </thead>
            <tbody>
              {findings.map((f) => (
                <tr key={f.finding_id} data-testid={`finding-${f.finding_id}`}>
                  <td>{CHECK_LABELS[f.check_type] || f.check_type}</td>
                  <td>{f.period_start ? `${f.period_start} → ${f.period_end}` : '—'}</td>
                  <td className="ae-num">{f.expected ?? '—'}</td>
                  <td className="ae-num">{f.actual ?? '—'}</td>
                  <td className="ae-num">{f.variance ?? '—'}</td>
                  <td style={{ whiteSpace: 'normal', maxWidth: 320 }}>{f.detail}</td>
                  <td>{fmtDateTime(f.detected_at)}</td>
                  <td>
                    {canResolve ? (
                      <div className="ae-field">
                        <input
                          type="text"
                          aria-label={`Resolution for finding ${f.finding_id}`}
                          placeholder="What was done?"
                          value={notes[f.finding_id] || ''}
                          onChange={(e) => setNotes((prev) => ({ ...prev, [f.finding_id]: e.target.value }))}
                        />
                        <button
                          type="button"
                          className="ae-btn"
                          disabled={savingId === f.finding_id}
                          onClick={() => resolve(f.finding_id)}
                        >
                          {savingId === f.finding_id ? 'Saving…' : 'Resolve'}
                        </button>
                      </div>
                    ) : (
                      <span className="ae-muted">Auditor only</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

export default ExceptionsPanel;
