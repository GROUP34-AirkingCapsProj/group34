/**
 * Workflow labels for Issuance feed cards (aligned with Transactions UI).
 *
 * The Receiving and Transfer helpers that used to live here were only ever
 * consumed by StockTransferManagement and TransferScanWorkflow, two pages that
 * were never routed and have since been deleted.
 */

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
