import React, { useState } from 'react';
import { issuanceOrderAPI } from '../../services/api';
import { toast } from '../../utils/toast';
import { confirmAction } from '../../utils/confirm';
import { packageBreakdown } from '../../utils/packageFormat';

/**
 * QuotePanel — Step 2 of the issuance wizard. Read-mostly review of the priced
 * quote before the order is confirmed and reservations are placed.
 *
 * Grand total is the largest type on the page (the number the customer reads).
 * Cost/margin render ONLY when `total_cost` is non-null — the server omits it as
 * an explicit null for roles without inventory_issuance.view_margin, so we branch
 * on the VALUE, never a client-side role check.
 *
 * Confirm & Start Picking posts confirmQuote (which returns the fresh, now-Pending
 * quote). When has_shortages is true it first opens the shared confirm dialog
 * naming the short lines, then re-posts with accept_shortages: true.
 *
 * Props:
 *   issuanceId       number
 *   quote            object   — quote `data` payload
 *   canIssue         boolean
 *   onQuoteUpdate(quoteData)  — after a line edit (updateLine/removeLine)
 *   onConfirmed(quoteData)    — after confirmQuote succeeds; parent advances to Pick
 *   onBack()                  — return to the Order step
 */

const fmtMoney = (val) => {
  const n = parseFloat(val);
  if (val === null || val === undefined || Number.isNaN(n)) return '—';
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

export default function QuotePanel({ issuanceId, quote, canIssue, onQuoteUpdate, onConfirmed, onBack }) {
  const [busy, setBusy] = useState(false);

  const lines = quote?.lines ?? [];
  const setGroups = quote?.set_groups ?? [];
  const looseLines = lines.filter((l) => l.issuance_set_id == null);
  const shortLines = lines.filter((l) => l.is_short);
  const showMargin = quote?.total_cost !== null && quote?.total_cost !== undefined;

  const run = async (fn) => {
    setBusy(true);
    try {
      const res = await fn();
      return res?.data ?? res;
    } catch (err) {
      toast.error(err.message || 'Action failed.');
      throw err;
    } finally {
      setBusy(false);
    }
  };

  const reduceToAvailable = async (line) => {
    if (line.available < 1) { await removeLine(line); return; }
    try {
      const data = await run(() => issuanceOrderAPI.updateLine(issuanceId, line.issuance_detail_id, { quantity: line.available }));
      if (data) onQuoteUpdate(data);
    } catch { /* handled */ }
  };

  const removeLine = async (line) => {
    if (!(await confirmAction({ title: `Remove ${line.product_name || 'this line'}?`, confirmText: 'Remove', tone: 'danger' }))) return;
    try {
      const data = await run(() => issuanceOrderAPI.removeLine(issuanceId, line.issuance_detail_id));
      if (data) onQuoteUpdate(data);
    } catch { /* handled */ }
  };

  const confirm = async () => {
    if (quote?.has_shortages) {
      const names = shortLines
        .map((l) => `${l.product_name || `Product #${l.product_id}`} (only ${l.available} of ${l.quantity_requested})`)
        .join(', ');
      const ok = await confirmAction({
        title: 'Confirm order with shortages?',
        text: `These lines cannot be fully served: ${names}. Proceeding will serve what is available.`,
        confirmText: 'Confirm short',
        tone: 'danger',
      });
      if (!ok) return;
    }
    try {
      const data = await run(() => issuanceOrderAPI.confirmQuote(issuanceId, quote?.has_shortages ? { accept_shortages: true } : {}));
      toast.success('Quote confirmed. Picking may begin.');
      if (data) onConfirmed(data);
    } catch { /* handled */ }
  };

  const renderLine = (l) => (
    <div key={l.issuance_detail_id} style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', padding: '0.45rem 0', borderBottom: '1px solid var(--ak-line)' }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600 }}>{l.product_name || `Product #${l.product_id}`}</div>
        <div className="ak-muted" style={{ fontSize: '0.8rem' }}>
          ×{l.quantity_requested}
          {packageBreakdown(l.quantity_requested, l.pieces_per_package, l.package_unit)
            && ` (${packageBreakdown(l.quantity_requested, l.pieces_per_package, l.package_unit)})`}
          {' · '}{fmtMoney(l.unit_price)} per piece
          {Number(l.pieces_per_package) > 1 && (
            <> · {fmtMoney(l.package_price)} per {l.package_unit || 'package'}</>
          )}
        </div>
        {l.is_short && (
          <div style={{ color: '#b91c1c', fontSize: '0.82rem', marginTop: 2 }}>
            Only {l.available} available — short by {l.short_by}
            <span style={{ marginLeft: '0.5rem', display: 'inline-flex', gap: '0.4rem' }}>
              <button type="button" className="ak-btn" onClick={() => reduceToAvailable(l)} disabled={busy || !canIssue}>
                Reduce to {l.available}
              </button>
              <button type="button" className="ak-btn" onClick={() => removeLine(l)} disabled={busy || !canIssue}>
                Remove
              </button>
            </span>
          </div>
        )}
      </div>
      <span style={{ minWidth: 100, textAlign: 'right', fontWeight: 600 }}>{fmtMoney(l.line_total)}</span>
    </div>
  );

  return (
    <div className="ak-field" style={{ gap: '1rem' }}>
      <div className="isp-session-header">
        <div className="isp-session-info">
          <span className="isp-session-dr">DR: {quote?.dr_number ?? '—'}</span>
          <span className="isp-session-sep">|</span>
          <span>{lines.length} line{lines.length !== 1 ? 's' : ''}</span>
          {quote?.has_shortages && (
            <>
              <span className="isp-session-sep">|</span>
              <span style={{ color: '#b91c1c', fontWeight: 600 }}>Has shortages</span>
            </>
          )}
        </div>
        <button type="button" className="isp-btn-secondary" onClick={onBack} disabled={busy}>Back to Order</button>
      </div>

      <div className="ak-card" style={{ padding: '1rem' }}>
        <h2 className="isp-card-title" style={{ marginTop: 0 }}>Review Quote</h2>

        {lines.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '28px 0', color: '#9ca3af' }}>No lines on this order.</div>
        ) : (
          <div style={{ display: 'grid', gap: '1rem' }}>
            {setGroups.map((g) => {
              const groupLines = lines.filter((l) => l.issuance_set_id === g.issuance_set_id);
              return (
                <div key={g.issuance_set_id} style={{ border: '1px solid var(--ak-line)', borderRadius: 10, padding: '0.8rem' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '0.5rem', marginBottom: '0.3rem' }}>
                    <div style={{ fontWeight: 700 }}>
                      {g.set_name_snapshot}
                      <span className="ak-muted" style={{ fontWeight: 400, marginLeft: '0.4rem' }}>×{g.set_quantity}</span>
                      {g.was_modified && <span className="ak-badge ak-badge--amber" style={{ marginLeft: '0.5rem' }}>Modified</span>}
                    </div>
                    <div style={{ fontWeight: 700 }}>{fmtMoney(g.group_total)}</div>
                  </div>
                  {groupLines.map(renderLine)}
                </div>
              );
            })}

            {looseLines.length > 0 && (
              <div style={{ border: '1px solid var(--ak-line)', borderRadius: 10, padding: '0.8rem' }}>
                <div style={{ fontWeight: 700, marginBottom: '0.3rem' }}>Custom items</div>
                {looseLines.map(renderLine)}
              </div>
            )}
          </div>
        )}

        {/* Totals — grand total is the largest type on the page. */}
        <div className="isp-totals" style={{ marginTop: '1.2rem' }}>
          <div className="isp-total-row"><span>Subtotal</span><span>{fmtMoney(quote?.subtotal)}</span></div>
          {parseFloat(quote?.discount_percent) > 0 && (
            <div className="isp-total-row isp-total-discount">
              <span>Discount ({quote?.discount_percent}%)</span><span>-{fmtMoney(quote?.discount_amount)}</span>
            </div>
          )}
          {showMargin && (
            <>
              <div className="isp-total-row"><span>Total cost</span><span>{fmtMoney(quote?.total_cost)}</span></div>
              <div className="isp-total-row"><span>Total margin</span><span>{fmtMoney(quote?.total_margin)}</span></div>
            </>
          )}
          <div className="isp-total-divider" />
          <div className="isp-total-row" style={{ alignItems: 'baseline' }}>
            <span style={{ fontSize: '1.1rem', fontWeight: 700 }}>Grand Total</span>
            <span style={{ fontSize: '2.2rem', fontWeight: 800, lineHeight: 1 }}>{fmtMoney(quote?.total_amount)}</span>
          </div>
        </div>

        <div className="isp-form-actions" style={{ marginTop: '1.4rem', justifyContent: 'flex-end' }}>
          <button
            type="button"
            className="isp-btn-complete"
            onClick={confirm}
            disabled={busy || !canIssue || lines.length === 0}
          >
            {busy ? 'Confirming…' : 'Confirm & Start Picking'}
          </button>
        </div>
      </div>
    </div>
  );
}
