// frontend/src/admin/utils/alertTarget.js
// Single source of truth: maps an alert item to a navigation target
// { path, focusKey } where focusKey is "<type>:<id>", or null when not navigable.

const AUDIT_TABLE_TARGETS = {
  purchase_orders:   (id) => ({ path: '/draft-po-creator', focusKey: `po:${id}` }),
  purchase_requests: (id) => ({ path: '/purchase-requests', focusKey: `pr:${id}` }),
  transfers:         (id) => ({ path: '/transfers',         focusKey: `transfer:${id}` }),
  products:          (id) => ({ path: '/items',             focusKey: `product:${id}` }),
  inventory:         (id) => ({ path: '/inventory',         focusKey: `inv:${id}` }),
};

export function pendingPoTarget(po) {
  const id = po?.id ?? po?.po_id;
  return id != null ? { path: '/draft-po-creator', focusKey: `po:${id}` } : null;
}

export function pendingPrTarget(pr) {
  const id = pr?.id ?? pr?.pr_id;
  return id != null ? { path: '/purchase-requests', focusKey: `pr:${id}` } : null;
}

// No wired approval destination yet — intentionally non-clickable.
export function pendingRegistrationTarget(/* _item */) {
  return null; // No destination wired yet — non-clickable.
}

export function lowStockTarget(item) {
  const id = item?.product_id ?? item?.inventory_id;
  return id != null
    ? { path: '/purchase-requests', query: 'tab=create' }
    : null;
}

export function auditTarget(entry) {
  if (!entry || entry.action === 'delete') return null;
  const builder = AUDIT_TABLE_TARGETS[entry.table_name];
  const id = entry.record_id;
  return builder && id != null ? builder(id) : null;
}

export function targetHref(target) {
  if (!target) return null;
  const params = new URLSearchParams(target.query || '');
  if (target.focusKey) params.set('focus', target.focusKey);
  const query = params.toString();
  return query ? `${target.path}?${query}` : target.path;
}
