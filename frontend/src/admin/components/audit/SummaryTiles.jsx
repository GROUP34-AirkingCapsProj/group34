import React from 'react';
import '../../styles/audit_evidence.css';

const fmtQty = (n) => Number(n || 0).toLocaleString('en-PH');
const fmtMoney = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const BASIS_LABELS = {
  actual: 'Actual',
  product_default: 'Product list cost',
  derived_weighted_avg: 'Derived average',
  unavailable: 'No cost recorded',
};

/**
 * The top of the funnel.
 *
 * Deliberately shows the cost basis beside the totals: a peso figure that is
 * partly evidence and partly reconstruction is two different claims, and an
 * auditor has to be able to tell them apart before citing either.
 */
const SummaryTiles = ({ summary, emptyReason }) => {
  if (!summary) {
    return (
      <div className="ae-empty" data-testid="summary-empty">
        {emptyReason === 'not-posted-yet'
          ? 'This movement type is not recorded in the ledger yet.'
          : 'No movements in this period.'}
      </div>
    );
  }

  const tiles = [
    { key: 'quantity-in', label: 'Quantity in', value: fmtQty(summary.quantity_in) },
    { key: 'quantity-out', label: 'Quantity out', value: fmtQty(summary.quantity_out) },
    { key: 'value-in', label: 'Value in', value: fmtMoney(summary.value_in) },
    { key: 'value-out', label: 'Value out', value: fmtMoney(summary.value_out) },
    { key: 'net-quantity', label: 'Net quantity', value: fmtQty(summary.net_quantity) },
    { key: 'events', label: 'Events', value: fmtQty(summary.event_count) },
  ];

  const basisRows = Object.entries(summary.cost_basis || {}).filter(([, v]) => Number(v) !== 0);

  return (
    <div className="ae-summary">
      <div className="ae-tile-grid">
        {tiles.map((t) => (
          <div key={t.key} className="ae-tile" data-testid={`tile-${t.key}`}>
            <p className="ae-tile-label">{t.label}</p>
            <p className="ae-tile-value">{t.value}</p>
          </div>
        ))}
      </div>

      <div className="ae-basis" data-testid="cost-basis-breakdown">
        <p className="ae-basis-title">What the value rests on</p>
        <ul className="ae-basis-list">
          {basisRows.length === 0 ? (
            <li className="ae-muted">No valued movements in this period.</li>
          ) : (
            basisRows.map(([basis, value]) => (
              <li key={basis}>
                <span className={`ae-basis-dot ae-basis-dot--${basis}`} aria-hidden="true" />
                {BASIS_LABELS[basis] || basis}: <strong>{fmtMoney(value)}</strong>
              </li>
            ))
          )}
        </ul>

        {Number(summary.unvalued_rows) > 0 && (
          <p className="ae-warn" data-testid="unvalued-warning">
            {fmtQty(summary.unvalued_rows)} movement row(s) have a quantity but no recoverable cost, so their
            value is not included in the totals above.
          </p>
        )}

        {Number(summary.reversal_count) > 0 && (
          <p className="ae-muted" data-testid="reversal-note">
            Includes {fmtQty(summary.reversal_count)} reversal row(s), which net against what they reversed.
          </p>
        )}
      </div>
    </div>
  );
};

export default SummaryTiles;
