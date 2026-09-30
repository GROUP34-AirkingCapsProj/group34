import React, { useMemo } from 'react';
import '../../styles/audit_evidence.css';

const fmtMoney = (n) =>
  n === null || n === undefined
    ? '—'
    : '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDateTime = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
      });
};

const BASIS_FLAG = {
  derived_weighted_avg: 'Derived',
  product_default: 'List cost',
  unavailable: 'No cost',
};

/**
 * Level 3.
 *
 * A reversal and the row it reversed are both struck through and both stay
 * visible: an auditor needs to see that something was undone, and a table that
 * quietly dropped the pair would hide exactly the event worth investigating.
 */
const MovementLedgerTable = ({ rows, onOpenEvidence, loading }) => {
  const reversedIds = useMemo(
    () => new Set((rows || []).map((r) => r.reverses_ledger_id).filter(Boolean)),
    [rows]
  );

  if (loading) {
    return <div className="ae-state">Loading movements…</div>;
  }

  if (!rows || rows.length === 0) {
    return (
      <div className="ae-state" data-testid="movements-empty">
        No movements match this scope.
      </div>
    );
  }

  return (
    <div className="ae-table-wrap">
      <table className="ae-table">
        <thead>
          <tr>
            <th>Occurred</th>
            <th>Type</th>
            <th>Product</th>
            <th>Batch / Serial</th>
            <th className="ae-num">Qty</th>
            <th className="ae-num">Unit cost</th>
            <th className="ae-num">Value</th>
            <th>By</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const isReversal = Boolean(r.reverses_ledger_id);
            const wasReversed = reversedIds.has(r.ledger_id);

            return (
              <tr
                key={r.ledger_id}
                data-testid={`row-${r.ledger_id}`}
                className={isReversal || wasReversed ? 'ae-row--reversed' : undefined}
              >
                <td>
                  {fmtDateTime(r.occurred_at)}
                  {r.is_backdated && (
                    <span className="ae-flag" title={`Recorded ${fmtDateTime(r.posted_at)}`}>Backdated</span>
                  )}
                </td>
                <td>
                  {String(r.movement_type || '').replace(/_/g, ' ')}
                  <span className="ae-flag">{r.direction}</span>
                  {isReversal && <span className="ae-flag">Reversal</span>}
                  {r.origin === 'reconstructed' && <span className="ae-flag">Reconstructed</span>}
                </td>
                <td>{r.product_name || (r.product_id ? `Product #${r.product_id}` : '—')}</td>
                <td>{r.serial_number || (r.batch_id ? `Batch #${r.batch_id}` : '—')}</td>
                <td className="ae-num">{Number(r.quantity || 0).toLocaleString('en-PH')}</td>
                <td className="ae-num">
                  {fmtMoney(r.unit_cost)}
                  {BASIS_FLAG[r.cost_basis] && <span className="ae-flag">{BASIS_FLAG[r.cost_basis]}</span>}
                </td>
                <td className="ae-num">{fmtMoney(r.extended_value)}</td>
                <td>{r.actor_name || 'System'}</td>
                <td>
                  <button type="button" className="ae-btn" onClick={() => onOpenEvidence(r.correlation_id)}>
                    Evidence
                  </button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};

export default MovementLedgerTable;
