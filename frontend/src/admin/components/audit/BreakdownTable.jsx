import React from 'react';
import '../../styles/audit_evidence.css';

const fmtQty = (n) => Number(n || 0).toLocaleString('en-PH');
const fmtMoney = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const DIMENSIONS = [
  { value: 'movement_type', label: 'Movement type' },
  { value: 'product', label: 'Product' },
  { value: 'user', label: 'User' },
  { value: 'source', label: 'Source document' },
  { value: 'cost_basis', label: 'Cost basis' },
];

/**
 * Level 2: pivot the period, then click a row to see the movements behind it.
 * This is the step that answers "where did that total come from" without making
 * the auditor scroll a ledger.
 */
const BreakdownTable = ({ rows, dimension, onDimensionChange, onDrillDown, loading }) => {
  const label = DIMENSIONS.find((d) => d.value === dimension)?.label ?? dimension;

  return (
    <div className="ae-card">
      <div className="ae-card-head">
        <div>
          <h2 className="ae-card-title">Breakdown</h2>
          <p className="ae-card-meta">Totals grouped by {label}</p>
        </div>
        <label className="ae-field">
          <span>Group by</span>
          <select value={dimension} onChange={(e) => onDimensionChange(e.target.value)}>
            {DIMENSIONS.map((d) => (
              <option key={d.value} value={d.value}>{d.label}</option>
            ))}
          </select>
        </label>
      </div>

      {loading ? (
        <div className="ae-state">Loading breakdown…</div>
      ) : !rows || rows.length === 0 ? (
        <div className="ae-state" data-testid="breakdown-empty">Nothing to group in this period.</div>
      ) : (
        <div className="ae-table-wrap">
          <table className="ae-table">
            <thead>
              <tr>
                <th>{label}</th>
                <th className="ae-num">Qty in</th>
                <th className="ae-num">Qty out</th>
                <th className="ae-num">Value in</th>
                <th className="ae-num">Value out</th>
                <th className="ae-num">Events</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.key} data-testid={`breakdown-${r.key}`}>
                  <td>{r.label}</td>
                  <td className="ae-num">{fmtQty(r.quantity_in)}</td>
                  <td className="ae-num">{fmtQty(r.quantity_out)}</td>
                  <td className="ae-num">{fmtMoney(r.value_in)}</td>
                  <td className="ae-num">{fmtMoney(r.value_out)}</td>
                  <td className="ae-num">{fmtQty(r.event_count)}</td>
                  <td>
                    <button type="button" className="ae-btn" onClick={() => onDrillDown(r)}>
                      View movements
                    </button>
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

export default BreakdownTable;
