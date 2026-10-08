import React from 'react';
import '../../styles/audit_evidence.css';

const fmtQty = (n) => Number(n || 0).toLocaleString('en-PH');
const fmtMoney = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * opening + in − out = closing, shown as the arithmetic rather than as a verdict,
 * so an auditor can check it by eye and click any term to see the rows behind it.
 *
 * The only claim this panel makes beyond the arithmetic is the comparison against
 * live stock — the one figure the ledger did not compute itself. Where the scope
 * covers more than one product+location that comparison is impossible, and the
 * panel says so rather than showing a reassuring tick it cannot justify.
 */
const ReconciliationPanel = ({ data, onDrillDown }) => {
  if (!data) {
    return (
      <div className="ae-card">
        <div className="ae-state" data-testid="recon-empty">
          Choose a product and a location to reconcile a period.
        </div>
      </div>
    );
  }

  const terms = [
    { key: 'opening-qty', label: 'Opening', qty: data.opening_quantity, value: data.opening_value, drill: null },
    { key: 'in-qty', label: 'Movements in', qty: data.quantity_in, value: data.value_in, drill: 'in' },
    { key: 'out-qty', label: 'Movements out', qty: data.quantity_out, value: data.value_out, drill: 'out' },
    { key: 'closing-qty', label: 'Closing', qty: data.closing_quantity, value: data.closing_value, drill: null },
  ];

  return (
    <div className="ae-card">
      <div className="ae-card-head">
        <div>
          <h2 className="ae-card-title">Reconciliation</h2>
          <p className="ae-card-meta">Opening + in − out = closing, for quantity and for value</p>
        </div>
      </div>

      <div className="ae-table-wrap">
        <table className="ae-table">
          <thead>
            <tr><th>Term</th><th className="ae-num">Quantity</th><th className="ae-num">Value</th><th /></tr>
          </thead>
          <tbody>
            {terms.map((t) => (
              <tr key={t.key}>
                <td>{t.label}</td>
                <td className="ae-num" data-testid={`recon-${t.key}`}>{fmtQty(t.qty)}</td>
                <td className="ae-num">{fmtMoney(t.value)}</td>
                <td>
                  {t.drill && (
                    <button type="button" className="ae-btn" onClick={() => onDrillDown(t.drill)}>
                      View rows
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div style={{ padding: '0 1rem 1rem' }}>
        {data.matches_stock === null ? (
          <p className="ae-muted" data-testid="recon-no-stock-check">
            Stock on hand is only comparable for a single product at a single location. Narrow the scope to
            check the closing figure against live stock.
          </p>
        ) : data.matches_stock ? (
          <p className="ae-muted" data-testid="recon-matches">
            Closing matches stock on hand ({fmtQty(data.stock_on_hand)}).
          </p>
        ) : (
          <p className="ae-warn" data-testid="recon-variance">
            Closing is {fmtQty(data.closing_quantity)} but stock on hand is {fmtQty(data.stock_on_hand)} — a
            variance of {fmtQty(data.stock_variance)}. Something moved without being recorded, or was recorded
            without moving.
          </p>
        )}

        {Number(data.unvalued_rows) > 0 && (
          <p className="ae-warn" data-testid="recon-unvalued">
            {fmtQty(data.unvalued_rows)} row(s) in this period have no recoverable cost, so the value column is
            proven only for the rows that do. The quantity column is complete.
          </p>
        )}
      </div>
    </div>
  );
};

export default ReconciliationPanel;
