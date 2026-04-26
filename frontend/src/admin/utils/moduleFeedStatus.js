/**
 * Workflow labels for Receiving / Transfer / Issuance feed cards (aligned with Transactions UI).
 */

export function receivingReceivedQty(r) {
  const details = Array.isArray(r?.details) ? r.details : [];
  const sum = details.reduce((s, d) => s + Number(d.quantity_amount ?? 0), 0);
  if (sum > 0) return sum;
  return Number(r?.total_quantity_received ?? 0) || 0;
}

export function poOrderedTotal(po) {
  if (!po?.details?.length) return 0;
  return po.details.reduce((s, d) => s + Number(d.quantity_ordered ?? 0), 0);
}

/** Receiving: To be received → On going (partial vs PO) → Completed */
export function getReceivingWorkflow(r) {
  const qty = receivingReceivedQty(r);
  if (qty <= 0) {
    return { key: 'tbr', label: 'To be received', badgeClass: 'txn-status-tbr' };
  }
  const ordered = poOrderedTotal(r.purchase_order);
  if (ordered > 0 && qty < ordered) {
    return { key: 'ongoing', label: 'On going', badgeClass: 'txn-status-ongoing' };
  }
  return { key: 'completed', label: 'Completed', badgeClass: 'txn-status-completed' };
}

/** Transfer: showroom receipt vs warehouse ship */
export function transferShippedQty(t) {
  const details = Array.isArray(t?.details) ? t.details : [];
  const s = details.reduce((a, d) => a + Number(d.quantity_transferred ?? 0), 0);
  if (s > 0) return s;
  return Number(t?.total_quantity_transferred ?? 0) || 0;
}

export function getTransferWorkflow(t) {
  const name = String(t?.status?.status_name || '').toLowerCase();
  if (name.includes('complete') || name.includes('received')) {
    return { key: 'done', label: 'Completed', badgeClass: 'txn-status-completed' };
  }
  if (name.includes('transit')) {
    return { key: 'transit', label: 'In transit', badgeClass: 'txn-status-intransit' };
  }
  const tr = Number(t?.total_quantity_transferred ?? 0);
  const recv = Number(t?.total_quantity_received ?? 0);
  if (tr > 0) {
    if (recv >= tr) return { key: 'done', label: 'Completed', badgeClass: 'txn-status-completed' };
    return { key: 'transit', label: 'In transit', badgeClass: 'txn-status-intransit' };
  }
  return { key: 'tbr', label: 'To be received', badgeClass: 'txn-status-tbr' };
}

export function issuanceLineQty(iss) {
  const details = Array.isArray(iss?.details) ? iss.details : [];
  return details.reduce((s, d) => s + Number(d.quantity_issued ?? 0), 0);
}

export function getIssuanceDisplay(iss) {
  const raw = iss?.status?.status_name;
  if (raw && String(raw).trim()) {
    const low = String(raw).toLowerCase();
    if (low.includes('pend')) return { label: raw, badgeClass: 'txn-status-pending' };
    if (low.includes('approv') || low.includes('complete') || low.includes('posted')) {
      return { label: raw, badgeClass: 'txn-status-completed' };
    }
    return { label: raw, badgeClass: 'txn-status-pending' };
  }
  const q = issuanceLineQty(iss);
  if (q > 0) return { label: 'Posted', badgeClass: 'txn-status-completed' };
  return { label: 'Open', badgeClass: 'txn-status-tbr' };
}
