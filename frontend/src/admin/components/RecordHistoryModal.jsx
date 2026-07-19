import React from 'react';
import Modal from './Modal';
import RecordHistoryTimeline from './RecordHistoryTimeline';
import '../styles/record_history.css';

const peso = (n) => `₱${Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const asDate = (s) => {
  if (!s) return null;
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return y && m && d ? `${m}/${d}/${y}` : String(s).slice(0, 10);
};

/* Build the spec sub-line for one line item (brand · capacity · unit · SKU). */
function itemDesc(it) {
  const parts = [
    it.brand_name,
    it.capacity_rating,
    it.variant,
    it.unit_name || it.unit_abbreviation,
    it.package_unit,
    it.product_code || it.product?.product_code,
  ].filter(Boolean);
  return parts.join(' · ');
}

/**
 * "Document trail" history modal for one PR or PO: a dark record header, the
 * line items (products), and the full non-collapsible lifecycle timeline.
 *
 * @param kind         "Purchase Order" | "Purchase Request"
 * @param record       the list item (carries details, supplier, location, totals)
 * @param statusLabel  human status label
 * @param statusColor  status color key (approved | pending | rejected | partial | draft | cancelled)
 * @param milestones   timeline milestones from the history endpoint
 */
export default function RecordHistoryModal({
  isOpen, onClose, kind = 'Record', record, statusLabel, statusColor = 'draft', milestones, loading,
}) {
  if (!record) return null;

  const number = record.po_number || record.pr_number || '';
  const items = Array.isArray(record.details) ? record.details : [];
  const total = record.grand_total ?? record.total_amount ?? record.estimated_total ?? null;
  const supplier = record.supplier?.supplier_name;
  const warehouse = record.location?.location_name;
  const dateStr = asDate(record.order_date || record.request_date);

  const meta = [supplier, warehouse, dateStr].filter(Boolean);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="" hideHeader maxWidth={640}>
      <div className="rhx">
        <div className="rhx-head">
          <button type="button" className="rhx-head-close" onClick={onClose} aria-label="Close">×</button>
          <div className="rhx-head-eyebrow">{kind} · History</div>
          <div className="rhx-head-main">
            <h2 className="rhx-number">{number}</h2>
            {statusLabel && <span className={`rhx-status rhx-status--${statusColor}`}>{statusLabel}</span>}
          </div>
          <div className="rhx-head-meta">
            {meta.map((part, i) => (
              <React.Fragment key={i}>
                {i > 0 && <span className="rhx-dot-sep">·</span>}
                <span>{part}</span>
              </React.Fragment>
            ))}
            {total != null && <span className="rhx-head-total">{peso(total)}</span>}
          </div>
        </div>

        <div className="rhx-body">
          <section className="rhx-section">
            <div className="rhx-eyebrow">Items <span className="rhx-count">{items.length}</span></div>
            {items.length === 0 ? (
              <div className="rh-empty">No line items recorded.</div>
            ) : (
              <div className="rhx-items">
                {items.map((it, i) => {
                  const qty = it.quantity_ordered ?? it.quantity ?? 0;
                  const desc = itemDesc(it);
                  const sub = it.subtotal ?? null;
                  return (
                    <div className="rhx-item" key={it.po_detail_id ?? it.pr_detail_id ?? i}>
                      <div>
                        <div className="rhx-item-title">{it.product_name || it.product?.product_name || `Product #${it.product_id}`}</div>
                        {desc && <div className="rhx-item-desc">{desc}</div>}
                      </div>
                      <div className="rhx-item-qty">×{qty}</div>
                      <div className="rhx-item-price">{sub != null ? peso(sub) : ''}</div>
                    </div>
                  );
                })}
              </div>
            )}
          </section>

          <section className="rhx-section">
            <div className="rhx-eyebrow">
              Timeline{Array.isArray(milestones) && milestones.length > 0 && <span className="rhx-count">{milestones.length}</span>}
            </div>
            <RecordHistoryTimeline milestones={milestones} loading={loading} />
          </section>
        </div>
      </div>
    </Modal>
  );
}
